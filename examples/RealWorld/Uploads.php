<?php

declare(strict_types=1);

namespace PhpstanContracts\Examples\RealWorld;

use PhpstanContracts\Attribute\Ensures;
use PhpstanContracts\Attribute\Requires;

final class Uploads
{
    /**
     * Accepts only PNG and JPEG file names that have a name before the extension.
     */
    #[Ensures('implies($result, str_ends_with($filename, ".png") || str_ends_with($filename, ".jpg"))')]
    #[Ensures('implies($result, strlen($filename) > 4)')]
    #[Ensures('implies(str_ends_with($filename, ".png") && strlen($filename) > 4, $result)')]
    public static function isAllowedImage(string $filename): bool
    {
        return (str_ends_with($filename, '.png') || str_ends_with($filename, '.jpg')) && strlen($filename) > 4;
    }

    /**
     * Only looks at the extension, so the name ".jpg" (a hidden file with no name) passes.
     */
    #[Ensures('implies($result, strlen($filename) > 4)')]
    public static function isAllowedImageNaive(string $filename): bool
    {
        return str_ends_with($filename, '.png') || str_ends_with($filename, '.jpg');
    }

    /**
     * Checks that the name contains an image extension, which also accepts "shell.png.php".
     */
    #[Ensures('implies($result, str_ends_with($filename, ".png") || str_ends_with($filename, ".jpg"))')]
    public static function isAllowedImageBroken(string $filename): bool
    {
        return str_contains($filename, '.png') || str_contains($filename, '.jpg');
    }

    /**
     * Staff coupon codes look like "STAFF-" followed by 6 characters.
     */
    #[Ensures('implies($result, strlen($code) === 12)')]
    #[Ensures('implies($result, str_starts_with($code, "STAFF-"))')]
    #[Ensures('implies($code === "STAFF-ABC123", $result)')]
    public static function isStaffCode(string $code): bool
    {
        return str_starts_with($code, 'STAFF-') && strlen($code) === 12;
    }

    /**
     * 3 to 20 characters, no space.
     */
    #[Ensures('implies($result, strlen($username) >= 3 && strlen($username) <= 20)')]
    #[Ensures('implies($result, !str_contains($username, " "))')]
    #[Ensures('implies(strlen($username) === 3 && !str_contains($username, " "), $result)')]
    public static function isValidUsername(string $username): bool
    {
        return strlen($username) >= 3 && strlen($username) <= 20 && !str_contains($username, ' ');
    }

    /**
     * Builds a storage key: "<tenant>/<name>", never starting with a separator.
     *
     * @param non-empty-string $tenant
     */
    #[Requires('!str_starts_with($tenant, "/")')]
    #[Ensures('strlen($result) === strlen($tenant) + 1 + strlen($name)')]
    #[Ensures('str_starts_with($result, $tenant)')]
    #[Ensures('!str_starts_with($result, "/")')]
    public static function storageKey(string $tenant, string $name): string
    {
        return $tenant . '/' . $name;
    }

    /**
     * Same, without any rule on the tenant.
     */
    #[Ensures('!str_starts_with($result, "/")')]
    public static function storageKeyBroken(string $tenant, string $name): string
    {
        return $tenant . '/' . $name;
    }
}
