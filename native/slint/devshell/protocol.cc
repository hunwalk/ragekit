#include "protocol.h"

namespace rk::dev {

std::string encode(const std::vector<std::string> &fields)
{
    std::string out;
    for (size_t i = 0; i < fields.size(); ++i) {
        if (i > 0) {
            out += '\t';
        }
        for (char c : fields[i]) {
            switch (c) {
            case '\\': out += "\\\\"; break;
            case '\t': out += "\\t"; break;
            case '\n': out += "\\n"; break;
            case '\r': out += "\\r"; break;
            default: out += c; break;
            }
        }
    }
    return out;
}

std::vector<std::string> decode(std::string_view line)
{
    if (!line.empty() && line.back() == '\n') {
        line.remove_suffix(1);
    }
    std::vector<std::string> fields(1);
    for (size_t i = 0; i < line.size(); ++i) {
        char c = line[i];
        if (c == '\t') {
            fields.emplace_back();
        } else if (c == '\\' && i + 1 < line.size()) {
            char next = line[++i];
            fields.back() += next == 't' ? '\t' : next == 'n' ? '\n' : next == 'r' ? '\r' : next;
        } else {
            fields.back() += c;
        }
    }
    return fields;
}

} // namespace rk::dev
