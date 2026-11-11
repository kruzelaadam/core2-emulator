#include "regs.h"

/* P0.0 is the LED. The delay is a 4-instruction loop x 4e6, about 1 s of
 * virtual time at PerformanceInMips 16, so the UI poll sees a stable level.
 * TOGGLES in SRAM counts edges in case two polls land on the same level. */
void Reset_Handler(void)
{
    uint32_t i;

    P0IOEN = 0x0001;
    P0DAT = 0x0001;
    *TOGGLES = 0;

    for (;;) {
        P0DAT = (uint16_t)(P0DAT ^ 0x0001);
        *TOGGLES += 1;
        for (i = 0; i < 4000000u; i++) {
            __asm volatile("nop");
        }
    }
}
