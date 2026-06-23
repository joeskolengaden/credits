# Makefile for the FPP "credits" plugin (prepaid / metered run-time gating).
#
#   make / make clean   build / remove the plugin shared library
# Override FPPDIR if FPP is not at /opt/fpp:  make FPPDIR=/path/to/fpp

PLUGIN  := credits
FPPDIR  ?= /opt/fpp
SRCDIR  ?= $(FPPDIR)/src

UNAME_S := $(shell uname -s)
ifeq ($(UNAME_S),Darwin)
  SHLIB_EXT   := .dylib
  SHLIB_FLAGS := -dynamiclib -undefined dynamic_lookup
  CXX         ?= clang++
else
  SHLIB_EXT   := .so
  SHLIB_FLAGS := -shared
  CXX         ?= g++
endif

TARGET  := lib$(PLUGIN)$(SHLIB_EXT)
CXXOBJS := src/CreditsPlugin.o

CXXFLAGS += -std=gnu++2a -fPIC -O2 -Wall -fvisibility=default -I$(SRCDIR)

.PHONY: all clean
all: $(TARGET)

$(TARGET): $(CXXOBJS)
	$(CXX) $(SHLIB_FLAGS) -o $@ $(CXXOBJS) -lpthread

src/%.o: src/%.cpp
	$(CXX) $(CXXFLAGS) -c -o $@ $<

clean:
	rm -f $(CXXOBJS) $(TARGET)
