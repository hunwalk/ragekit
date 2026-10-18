// Decodes each stdin line and re-encodes it: ProtocolTest compares the result with PHP's encoding.
#include "protocol.h"
#include <iostream>
#include <string>
int main()
{
    std::string line;
    while (std::getline(std::cin, line)) {
        std::cout << rk::dev::encode(rk::dev::decode(line)) << "\n";
    }
}
