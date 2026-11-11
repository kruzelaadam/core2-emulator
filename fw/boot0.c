#include "regs.h"

/* Stand-in for the first stage at 0x0000..0x1FFF, which is not part of the
 * Suunto images. Those carry a header in the reserved vector words:
 * word 7 and word 10 = 0xDA11C10C, word 8 = load address, word 9 = end,
 * word 13 = version. Boots the first valid one of BL1, BL2, application:
 * VTOR = image, MSP = word 0, jump to word 1. The real stage 0 likely also
 * checks a CRC and an update flag; that logic is unknown.
 * BOOT_HINT (last word of the stage 0 area, outside this image) names a
 * slot to try first; the console writes it to boot the application
 * directly. */

#define HDR_MAGIC 0xDA11C10Cu
#define SCB_VTOR (*(volatile uint32_t *)0xE000ED08)
#define SRAM_TOP 0x20020000u
#define BOOT_HINT (*(volatile uint32_t *)0x00001FFCu)

static const uint32_t slots[] = { 0x00002000u, 0x0000A000u, 0x00012000u };

static int valid(uint32_t base)
{
    const volatile uint32_t *v = (const volatile uint32_t *)base;

    if (base < 0x2000u || base >= 0x80000u || (base & 0x3FFu) != 0) {
        return 0;
    }
    if (v[7] != HDR_MAGIC || v[10] != HDR_MAGIC || v[8] != base) {
        return 0;
    }
    if (v[0] < 0x20000000u || v[0] > SRAM_TOP) {
        return 0;
    }
    return v[1] > base && v[1] < v[9];
}

static void __attribute__((noreturn)) boot(uint32_t base)
{
    const volatile uint32_t *v = (const volatile uint32_t *)base;

    SCB_VTOR = base;
    __asm volatile(
        "msr msp, %0\n"
        "bx %1\n"
        :
        : "r"(v[0]), "r"(v[1]));
    __builtin_unreachable();
}

void Reset_Handler(void)
{
    uint32_t i;

    if (valid(BOOT_HINT)) {
        boot(BOOT_HINT);
    }
    for (i = 0; i < sizeof(slots) / sizeof(slots[0]); i++) {
        if (valid(slots[i])) {
            boot(slots[i]);
        }
    }
    hang(0xB0070000u);
}
