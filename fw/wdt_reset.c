#include "regs.h"

/* First entry starts WDT2 and spins. The timeout resets the core.
 * SRAM holds the boot count. The second entry parks so the count stays 2. */
void Reset_Handler(void)
{
    uint32_t n = *BOOTS + 1;
    *BOOTS = n;
    if (n >= 2) {
        hang(0xC0FFEE02);
    }

    unlock();
    WDT2CLK = 0x0000;
    WDT2CMP = 0x0001;
    WDT2CTL = 0x0011;
    lock();

    for (;;) {
        __asm volatile("nop");
    }
}
