import re

with open("/fleetbase/api/vendor/fleetbase/core-api/src/Providers/CoreServiceProvider.php.orig", "r") as f:
    content = f.read()

# Remove all debug lines
content = re.sub(r'\nfile_put_contents\([^)]*\);', "", content)
content = re.sub(r'\n?\$GLOBALS\[.*?\];', "", content)
content = re.sub(r'\n"?,?\s*FILE_APPEND\);', "", content)

# Clean up empty lines at end
while content.endswith("\n\n"):
    content = content[:-1]

# Now find the boot() method and add debug code at the start
boot_pos = content.find("public function boot()")
if boot_pos == -1:
    print("ERROR: boot() not found")
    exit(1)

# Find the opening brace of boot()
brace_pos = content.find("{", boot_pos)
insert_pos = brace_pos + 1

debug_code = '\n        file_put_contents("/tmp/boot-bt.log", date("Y-m-d H:i:s.u") . " PID=" . getmypid() . " BOOT callCount=" . ($GLOBALS["__bootCount"] ?? 0) . "\\n" . json_encode(array_map(function($f) { return ($f["file"] ?? "?") . ":" . ($f["line"] ?? "?") . " " . ($f["function"] ?? "?"); }, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15))) . "\\n---\\n", FILE_APPEND);\n        $GLOBALS["__bootCount"] = ($GLOBALS["__bootCount"] ?? 0) + 1;\n'

content = content[:insert_pos] + debug_code + content[insert_pos:]

with open("/fleetbase/api/vendor/fleetbase/core-api/src/Providers/CoreServiceProvider.php", "w") as f:
    f.write(content)
print("Done")
