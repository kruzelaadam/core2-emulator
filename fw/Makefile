CC ?= arm-none-eabi-gcc
CFLAGS := -mcpu=cortex-m0plus -mthumb -Os -ffreestanding -nostdlib -Wall -Wextra -ffunction-sections -fdata-sections
LDFLAGS := -nostdlib -T linker.ld -Wl,--gc-sections

.PHONY: all clean

all: happy.elf wdt_reset.elf blink.elf core_demo.elf boot0.elf

happy.elf: happy.c regs.h linker.ld
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ happy.c

wdt_reset.elf: wdt_reset.c regs.h linker.ld
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ wdt_reset.c

blink.elf: blink.c regs.h linker.ld
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ blink.c

core_demo.elf: core_demo.c regs.h linker.ld
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ core_demo.c

boot0.elf: boot0.c regs.h linker.ld
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ boot0.c

clean:
	rm -f happy.elf wdt_reset.elf blink.elf core_demo.elf boot0.elf
