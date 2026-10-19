# Host build of the portable firmware core and its unit tests.
CXX ?= g++
CXXFLAGS ?= -std=c++11 -O1 -g -Wall -Wextra -Werror -Wno-unused-parameter \
            -fsanitize=address,undefined -fno-omit-frame-pointer
SRC = ../src/sha256.cpp ../src/json_lite.cpp ../src/http_response.cpp \
      ../src/device_request.cpp ../src/doorlock_core.cpp
HEADERS = ../src/sha256.h ../src/json_lite.h ../src/http_response.h \
          ../src/device_request.h ../src/doorlock_core.h

.PHONY: all test clean
all: test

test_core: test_core.cpp $(SRC) $(HEADERS)
	$(CXX) $(CXXFLAGS) -o $@ test_core.cpp $(SRC)

live_request: live_request.cpp $(SRC) $(HEADERS)
	$(CXX) $(CXXFLAGS) -o $@ live_request.cpp $(SRC)

test: test_core
	./test_core

clean:
	rm -f test_core live_request
