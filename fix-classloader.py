import re

with open("/fleetbase/api/vendor/composer/ClassLoader.php", "r") as f:
    content = f.read()

# Remove debug blocks by finding and removing them
# Remove the BEFORE debug block (if $class === ... }
lines = content.split("\n")
clean_lines = []
skip_until_brace = False
for i, line in enumerate(lines):
    if 'if ($class === "Fleetbase\\Models\\User"' in line and 'ce=N' not in line:
        # Check if this is the BEFORE block (inside loadClass, before findFile)
        # Skip this block until we find the closing }
        skip_until_brace = True
        continue
    if skip_until_brace:
        if line.strip() == "}":
            skip_until_brace = False
            continue
        continue
    # Also skip AFTER blocks
    if 'if ($class === "Fleetbase\\Models\\User"' in line:
        skip_until_brace = True
        continue
    clean_lines.append(line)

content = "\n".join(clean_lines)

# Now replace loadClass with guarded version
old = """    public function loadClass($class)
    {
        if ($file = $this->findFile($class)) {
            $includeFile = self::$includeFile;
            $includeFile($file);

            return true;
        }

        return null;
    }"""

new = """    private static $loaded = [];

    public function loadClass($class)
    {
        if (isset(self::$loaded[$class])) {
            if (!class_exists($class, false) && !interface_exists($class, false) && !trait_exists($class, false)) {
                $file = $this->findFile($class);
                if ($file) {
                    $includeFile = self::$includeFile;
                    @$includeFile($file);
                }
            }
            return true;
        }

        if ($file = $this->findFile($class)) {
            self::$loaded[$class] = true;
            $includeFile = self::$includeFile;
            $includeFile($file);

            return true;
        }

        return null;
    }"""

content = content.replace(old, new)

with open("/fleetbase/api/vendor/composer/ClassLoader.php", "w") as f:
    f.write(content)
print("Done")
