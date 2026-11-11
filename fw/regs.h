typedef unsigned short uint16_t;
typedef unsigned int uint32_t;

#define REG16(addr) (*(volatile uint16_t *)(addr))

#define SYSPROT   REG16(0x40000000)
#define CLGSCLK   REG16(0x40000040)
#define CLGOSC    REG16(0x40000042)
#define CLGIOSC   REG16(0x40000044)
#define CLGINTF   REG16(0x4000004C)
#define WDT2CLK   REG16(0x400000A0)
#define WDT2CTL   REG16(0x400000A2)
#define WDT2CMP   REG16(0x400000A4)
#define P0DAT     REG16(0x40000200)
#define P0IOEN    REG16(0x40000202)
#define LCD32BCLK REG16(0x40000800)
#define LCD32BCTL REG16(0x40000802)
#define LCD32BTIM1 REG16(0x40000804)
#define LCD32BTIM2 REG16(0x40000806)
#define LCD32BPWR REG16(0x40000808)
#define LCD32BDSP REG16(0x4000080A)
#define LCD32BCOMC0 REG16(0x4000080C)
#define LCD32BCOMC1 REG16(0x4000080E)

#define MAGIC ((volatile uint32_t *)0x20000000)
#define BOOTS ((volatile uint32_t *)0x20000004)
#define TOGGLES ((volatile uint32_t *)0x20000008)
#define DISPRAM ((volatile uint16_t *)0x20200000)

#define PROT_KEY 0x0096u

extern uint32_t _stack_top;

void Reset_Handler(void);
void NMI_Handler(void);
void HardFault_Handler(void);

__attribute__((section(".vectors"), used, aligned(4)))
const uint32_t vectors[] = {
    (uint32_t)&_stack_top,
    (uint32_t)Reset_Handler,
    (uint32_t)NMI_Handler,
    (uint32_t)HardFault_Handler,
};

static void hang(uint32_t code)
{
    *MAGIC = code;
    for (;;) {
        __asm volatile("nop");
    }
}

void NMI_Handler(void)
{
    hang(0x00E10000);
}

void HardFault_Handler(void)
{
    hang(0xDEADDEAD);
}

static inline void unlock(void)
{
    SYSPROT = PROT_KEY;
}

static inline void lock(void)
{
    SYSPROT = 0;
}
