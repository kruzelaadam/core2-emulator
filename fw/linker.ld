OUTPUT_FORMAT("elf32-littlearm")
OUTPUT_ARCH(arm)
ENTRY(Reset_Handler)

MEMORY
{
    FLASH (rx) : ORIGIN = 0x00000000, LENGTH = 512K
    RAM (rwx)  : ORIGIN = 0x20000000, LENGTH = 128K
}

_stack_top = ORIGIN(RAM) + LENGTH(RAM);

SECTIONS
{
    .text : {
        KEEP(*(.vectors))
        *(.text*)
        *(.rodata*)
    } > FLASH

    /* 0x20000000..0x2000000B are magic/boots/toggles. No .data and no
     * startup zeroing: images must not rely on initialised RAM. */
    .bss 0x20000100 (NOLOAD) : {
        *(.bss*)
        *(COMMON)
    } > RAM
}
