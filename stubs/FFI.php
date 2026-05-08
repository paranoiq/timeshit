<?php

namespace {

    use FFI\CData;
    use FFI\CType;

    class FFI
    {

        public const __BIGGEST_ALIGNMENT__ = 1;

        public static function addr(CData &$ptr): CData {}

        public static function alignof(CData|CType &$ptr): int {}

        /**
         * @param non-empty-list<int> $dimensions
         */
        public static function arrayType(CType $type, array $dimensions): CType {}

        public function cast(CType|string $type, CData|int|float|bool|null &$ptr): ?CData {}

        public static function cdef(string $code = "", ?string $lib = null): self {}

        public static function free(CData &$ptr): void {}

        public static function isNull(CData &$ptr): bool {}

        public static function load(string $filename): ?self {}

        public static function memcmp(string|CData &$ptr1, string|CData &$ptr2, int $size): int {}

        public static function memcpy(CData &$to, CData|string &$from, int $size): void {}

        public static function memset(CData &$ptr, int $value, int $size): void {}

        public function new(CType|string $type, bool $owned = true, bool $persistent = false): ?CData {}

        public static function scope(string $name): self {}

        public static function sizeof(CData|CType &$ptr): int {}

        public static function string(CData &$ptr, ?int $size = null): string {}

        public function type(string $type): ?CType {}

        public static function typeof(CData &$ptr): CType {}
    }

}

namespace FFI {

    class CData
    {
    }

    class CType
    {

        public const TYPE_VOID = 1;
        public const TYPE_FLOAT = 2;
        public const TYPE_DOUBLE = 3;
        public const TYPE_LONGDOUBLE = 4;
        public const TYPE_UINT8 = 5;
        public const TYPE_SINT8 = 6;
        public const TYPE_UINT16 = 7;
        public const TYPE_SINT16 = 8;
        public const TYPE_UINT32 = 9;
        public const TYPE_SINT32 = 10;
        public const TYPE_UINT64 = 11;
        public const TYPE_SINT64 = 12;
        public const TYPE_ENUM = 13;
        public const TYPE_BOOL = 14;
        public const TYPE_CHAR = 15;
        public const TYPE_POINTER = 16;
        public const TYPE_FUNC = 17;
        public const TYPE_ARRAY = 18;
        public const TYPE_STRUCT = 19;
        public const ATTR_CONST = 20;
        public const ATTR_INCOMPLETE_TAG = 21;
        public const ATTR_VARIADIC = 22;
        public const ATTR_INCOMPLETE_ARRAY = 23;
        public const ATTR_VLA = 24;
        public const ATTR_UNION = 25;
        public const ATTR_PACKED = 26;
        public const ATTR_MS_STRUCT = 27;
        public const ATTR_GCC_STRUCT = 28;
        public const ABI_DEFAULT = 29;
        public const ABI_CDECL = 30;
        public const ABI_FASTCALL = 31;
        public const ABI_THISCALL = 32;
        public const ABI_STDCALL = 33;
        public const ABI_PASCAL = 34;
        public const ABI_REGISTER = 35;
        public const ABI_MS = 36;
        public const ABI_SYSV = 37;
        public const ABI_VECTORCALL = 38;

        public function getAlignment(): int
        {
        }

        public function getArrayElementType(): self
        {
        }

        public function getArrayLength(): int
        {
        }

        public function getAttributes(): int
        {
        }

        public function getEnumKind(): int
        {
        }

        public function getFuncABI(): int
        {
        }

        public function getFuncParameterCount(): int
        {
        }

        public function getFuncParameterType(int $index): self
        {
        }

        public function getFuncReturnType(): self
        {
        }

        public function getKind(): int
        {
        }

        public function getName(): string
        {
        }

        public function getPointerType(): self
        {
        }

        public function getSize(): int
        {
        }

        /**
         * @return array<string>
         */
        public function getStructFieldNames(): array
        {
        }

        public function getStructFieldOffset(string $name): int
        {
        }

        public function getStructFieldType(string $name): self
        {
        }

    }

}
