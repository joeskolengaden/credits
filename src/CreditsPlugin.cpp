/*
 * FPP "credits" plugin  -  prepaid / metered run-time gating (FPP 5.4 - 9.x)
 *
 * The device is granted a balance of "credits". While fppd runs it burns one
 * credit per hour (configurable). When the balance reaches zero the plugin
 * forces every channel to 0 in modifyChannelData, so NO lights output until the
 * balance is topped up by an admin. An admin (password-gated, in the web UI)
 * sets the credit balance and enables/disables the plugin.
 *
 * No-RTC design
 * -------------
 * The target device has no real-time clock and its wall-clock time cannot be
 * trusted (it may be wrong at boot and jump when NTP later syncs). So we measure
 * elapsed time with std::chrono::steady_clock (CLOCK_MONOTONIC) which counts
 * seconds since boot and is immune to the wall clock being absent or jumping.
 * The remaining balance is persisted to disk so it survives reboots; monotonic
 * time only accrues while fppd is actually running (no credit is burned while the
 * device is powered off), which is exactly the "per running hour" meter we want.
 *
 * Conventions shared with pixelfx / pixelpulse: settings live in
 * config/plugin.credits (re-read by a worker thread), a live snapshot is written
 * to /dev/shm/credits_status.json for the settings page (RAM, no SD wear, and
 * readable by Apache's PrivateTmp namespace which a /tmp file would not be), and
 * the durable balance is kept in config/credits_state.txt.
 */
#include <algorithm>
#include <atomic>
#include <chrono>
#include <cmath>
#include <condition_variable>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <fstream>
#include <mutex>
#include <sstream>
#include <string>
#include <thread>

#include "Plugin.h"
#include "Sequence.h"

namespace {
long toLong(const std::string& v, long d) {
    if (v.empty()) return d;
    char* e = nullptr;
    long r = std::strtol(v.c_str(), &e, 10);
    return e == v.c_str() ? d : r;
}
double toDouble(const std::string& v, double d) {
    if (v.empty()) return d;
    char* e = nullptr;
    double r = std::strtod(v.c_str(), &e);
    return e == v.c_str() ? d : r;
}

// Durable balance (survives reboot) and the volatile UI snapshot.
const char* const kStatePath  = "/home/fpp/media/config/credits_state.txt";
const char* const kStatusPath = "/dev/shm/credits_status.json";
// Cooperative pipeline gate: companion FPP plugins (pixelfx/pixelpulse) read this
// and bail out of their own modifyChannelData when it is "1", so the blackout
// holds no matter which plugin FPP runs last (plugins load in readdir order, so
// ordering can't be relied on). "1" = out of credits, "0" = normal.
const char* const kBlockPath  = "/dev/shm/credits_block";
}  // namespace

class CreditsPlugin : public FPPPlugin {
public:
    CreditsPlugin() : FPPPlugin("credits") {
        loadState();
        auto now = std::chrono::steady_clock::now();
        mLastTick = mLastPersist = mLastStatus = now;
        applySettings();   // base ctor already filled `settings`
        writeStatus();
        writeBlockFlag(false);
        mWorker = std::thread([this] { workerLoop(); });
    }

    ~CreditsPlugin() override {
        {
            std::lock_guard<std::mutex> lk(mMx);
            mStop = true;
        }
        mCv.notify_all();
        if (mWorker.joinable()) mWorker.join();
        persistState();    // flush the balance on shutdown
        writeBlockFlag(false);   // don't leave companions blocked if credits unloads
    }

    // Hot path: lock-free reads of the atomics the worker maintains. When the
    // balance is exhausted (and the plugin is enabled) we blank the output.
    void modifyChannelData(int /*ms*/, uint8_t* d) override {
        if (!mEnabled.load(std::memory_order_relaxed)) return;     // plugin off -> passthrough
        if (!mBlocking.load(std::memory_order_relaxed)) return;    // have credits -> passthrough
        if (d == nullptr) return;
        long n = mBlankChannels.load(std::memory_order_relaxed);
        if (n < 0) n = 0;
        if (n > (long)FPPD_MAX_CHANNELS) n = (long)FPPD_MAX_CHANNELS;
        std::memset(d, 0, (size_t)n);   // out of credits -> everything dark
    }

private:
    std::string cfg(const std::string& k) const {
        auto it = settings.find(k);
        return it == settings.end() ? std::string() : it->second;
    }

    void applySettings() {
        mEnabled.store(toLong(cfg("enabled"), 0) != 0, std::memory_order_relaxed);
        mSecondsPerCredit = std::max(1.0, toDouble(cfg("seconds_per_credit"), 3600.0));
        mCountMode = (cfg("count_mode") == "playing") ? 1 : 0;
        long bc = toLong(cfg("blank_channels"), 524288);
        if (bc < 0) bc = 0;
        if (bc > (long)FPPD_MAX_CHANNELS) bc = (long)FPPD_MAX_CHANNELS;
        mBlankChannels.store(bc, std::memory_order_relaxed);
    }

    // Admin top-up handshake: the UI writes recharge_value + a fresh
    // recharge_token; when we see a token we have not applied we set the balance
    // to that value once. The applied token is persisted so a restart does not
    // re-apply the same top-up.
    void handleRecharge() {
        std::string tok = cfg("recharge_token");
        if (tok.empty() || tok == mAppliedToken) return;
        double v = toDouble(cfg("recharge_value"), mRemaining);
        if (v < 0) v = 0;
        mRemaining = v;
        mConsumed = 0.0;
        mAppliedToken = tok;
        persistState();
    }

    void tick() {
        auto now = std::chrono::steady_clock::now();
        double dt = std::chrono::duration<double>(now - mLastTick).count();
        mLastTick = now;
        if (dt < 0) dt = 0;
        if (dt > 3600) dt = 3600;   // guard against clock weirdness

        bool active = mEnabled.load(std::memory_order_relaxed) && mRemaining > 0.0;
        if (active && mCountMode == 1)
            active = (sequence != nullptr && sequence->IsSequenceRunning());

        if (active) {
            double used = dt / mSecondsPerCredit;
            mRemaining -= used;
            mConsumed += used;
            if (mRemaining < 0) mRemaining = 0;
        }
        bool blk = mEnabled.load(std::memory_order_relaxed) && mRemaining <= 1e-9;
        mBlocking.store(blk, std::memory_order_relaxed);
    }

    // Publish the cooperative gate flag. Only the worker thread writes it, so
    // there's no cross-thread race; rewriting it each tick self-heals if the RAM
    // file is ever cleared.
    void writeBlockFlag(bool blk) {
        FILE* f = fopen(kBlockPath, "w");
        if (!f) return;
        fputc(blk ? '1' : '0', f);
        fclose(f);
    }

    void workerLoop() {
        for (;;) {
            std::unique_lock<std::mutex> lk(mMx);
            mCv.wait_for(lk, std::chrono::milliseconds(500), [this] { return mStop; });
            if (mStop) break;
            lk.unlock();

            reloadSettings();   // re-read config/plugin.credits
            applySettings();
            handleRecharge();
            tick();
            writeBlockFlag(mBlocking.load());
            maybePersist();
            writeStatus();
        }
    }

    // Limit SD-card wear: persist every 60s, and immediately whenever a whole
    // credit boundary is crossed (or we hit zero) so a sudden power loss never
    // loses more than the current minute / fractional credit.
    void maybePersist() {
        auto now = std::chrono::steady_clock::now();
        bool boundary = std::floor(mRemaining) != std::floor(mLastPersistedRemaining);
        bool hitZero  = (mRemaining <= 1e-9) && (mLastPersistedRemaining > 1e-9);
        if (boundary || hitZero || (now - mLastPersist >= std::chrono::seconds(60))) {
            persistState();
            mLastPersist = now;
        }
    }

    void persistState() {
        FILE* f = fopen(kStatePath, "w");
        if (!f) return;
        fprintf(f, "CREDITS_STATE 1\n");
        fprintf(f, "remaining %.6f\n", mRemaining);
        fprintf(f, "consumed %.6f\n", mConsumed);
        fprintf(f, "token %s\n", mAppliedToken.empty() ? "-" : mAppliedToken.c_str());
        fclose(f);
        mLastPersistedRemaining = mRemaining;
    }

    void loadState() {
        std::ifstream in(kStatePath);
        if (!in) { mLastPersistedRemaining = mRemaining; return; }
        std::string line;
        while (std::getline(in, line)) {
            std::istringstream ss(line);
            std::string key;
            ss >> key;
            if (key == "remaining") ss >> mRemaining;
            else if (key == "consumed") ss >> mConsumed;
            else if (key == "token") { std::string t; ss >> t; if (t != "-") mAppliedToken = t; }
        }
        if (mRemaining < 0) mRemaining = 0;
        mLastPersistedRemaining = mRemaining;
    }

    void writeStatus() {
        auto now = std::chrono::steady_clock::now();
        if (now - mLastStatus < std::chrono::milliseconds(500)) return;
        mLastStatus = now;
        FILE* f = fopen(kStatusPath, "w");
        if (!f) return;
        bool playing = (sequence != nullptr && sequence->IsSequenceRunning());
        fprintf(f,
            "{\"enabled\":%s,\"blocking\":%s,\"remaining\":%.4f,\"consumed\":%.4f,"
            "\"secondsPerCredit\":%.0f,\"countMode\":\"%s\",\"playing\":%s}",
            mEnabled.load() ? "true" : "false",
            mBlocking.load() ? "true" : "false",
            mRemaining, mConsumed, mSecondsPerCredit,
            mCountMode == 1 ? "playing" : "running",
            playing ? "true" : "false");
        fclose(f);
    }

    // --- read by modifyChannelData (hot path) ---
    std::atomic<bool> mEnabled{false};
    std::atomic<bool> mBlocking{false};
    std::atomic<long> mBlankChannels{524288};

    // --- owned by the worker thread ---
    double mRemaining = 0.0;             // credits left (fractional)
    double mConsumed = 0.0;              // credits used since last top-up
    double mSecondsPerCredit = 3600.0;   // 1 credit = 1 hour by default
    int mCountMode = 0;                  // 0 = while running, 1 = only while playing
    std::string mAppliedToken;
    double mLastPersistedRemaining = -1.0;
    std::chrono::steady_clock::time_point mLastTick, mLastPersist, mLastStatus;

    std::thread mWorker;
    std::mutex mMx;
    std::condition_variable mCv;
    bool mStop = false;
};

extern "C" {
FPPPlugin* createPlugin() { return new CreditsPlugin(); }
}
