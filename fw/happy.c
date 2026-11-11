#include "regs.h"

/* IOSCEN 0->1 sets CLGINTF.IOSCSTAIF. Reset leaves the flag clear. */
static void clocks(void)
{
    uint32_t spins;

    unlock();
    CLGIOSC = 0x0006; /* 16 MHz */
    CLGSCLK = 0x0000; /* IOSC / 1 */
    lock();

    CLGOSC = 0x0F00;
    CLGINTF = 0x0001;
    CLGOSC = 0x0F01;

    spins = 0;
    while ((CLGINTF & 0x1) == 0) {
        if (++spins > 100000u) {
            hang(0xC10C0000u | CLGINTF);
        }
    }
}

static void watchdog(void)
{
    unlock();
    /* IOSC / 65536 ~= 244 Hz at 16 MHz. CMP max, kick while starting. */
    WDT2CLK = 0x0000;
    WDT2CMP = 0x03FF;
    WDT2CTL = 0x0011;
    lock();
}

void Reset_Handler(void)
{
    uint32_t i;

    clocks();
    watchdog();

    P0IOEN = 0x0001;
    P0DAT = 0x0001;
    if (P0DAT != 0x0001) {
        hang(0xBAD00001);
    }

    for (i = 0; i < 352; i++) {
        DISPRAM[i] = 0xA55A;
    }

    LCD32BCTL = 0x0001;
    hang(0xC0FFEE01);
}
