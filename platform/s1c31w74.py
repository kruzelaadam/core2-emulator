# Epson S1C31W74 peripheral window, 0x40000000, size 0x3000.
# IronPython 2. The script scope persists across accesses. `request` and
# `self` are injected by Renode.
#
# SRAM is not cleared on WDT reset (it survives on silicon, and the reset
# test counts boots in RAM). LimitTimer expiry resets this block and the
# CPU/NVIC only. machine.RequestReset() would wipe MappedMemory.

PROT_KEY = 0x0096

SYSPROT = 0x000
PWGACTL = 0x020
CLGSCLK = 0x040
CLGOSC = 0x042
CLGIOSC = 0x044
CLGOSC1 = 0x046
CLGOSC3 = 0x048
CLGINTF = 0x04C
CLGINTE = 0x04E
CLGFOUT = 0x050
WDT2CLK = 0x0A0
WDT2CTL = 0x0A2
WDT2CMP = 0x0A4
FLASHC = 0x1B0
P0DAT = 0x200
P0IOEN = 0x202
LCD_BASE = 0x800
LCD_END = 0x812
LCDCTL = 0x802
LCDDSP = 0x80A
LCDINTF = 0x810

# Writable bits per LCD32B register, chapter 20.8.
LCD_MASK = {
    0x800: 0x0173,
    0x802: 0x0003,
    0x804: 0x1F1F,
    0x806: 0x031F,
    0x808: 0x8F1D,
    0x80A: 0x0077,
    0x80C: 0xFFFF,
    0x80E: 0xFFFF,
    0x812: 0x0001,
}

# T16 16-bit timers: +0 CLK, +2 MOD, +4 CTL, +6 TR, +8 TC, +A INTF, +C INTE.
# Base -> NVIC line, taken from the Suunto vector tables: the IRQ5 handler
# clears 0x4000016A, BL1's IRQ7 handler clears 0x400003AA, the app's IRQ18
# handler clears 0x4000048A (beep end) and its IRQ13 handler clears
# 0x4000068A (software PWM on P0.2). Every channel must be modelled: the
# driver busy-waits on PRESET after writing TR.
T16 = {0x160: 5, 0x3A0: 7, 0x480: 18, 0x680: 13}
T16_CLK = 0x0
T16_MOD = 0x2
T16_CTL = 0x4
T16_TR = 0x6
T16_TC = 0x8
T16_INTF = 0xA
T16_INTE = 0xC

# I2C masters: +0 CLK, +2 MOD, +4 BR, +8 OADR, +A CTL, +C TXD, +E RXD,
# +10 INTF, +12 INTE. Bases from the Suunto i2cBusImpl.c driver.
# CTL: MODEN b0, SFTRST b1, TXSTART b2, TXSTOP b3, TXNACK b4, MST b5.
# INTF: TBEIF b0, RBFIF b1, ERRIF b2, STARTIF b3, STOPIF b4, NACKIF b5,
# BYTEENDIF b7; b2..b7 are write-1-to-clear.
I2C = {0x3C0: 'i2c0', 0x6C0: 'i2c1'}
I2C_CTL = 0xA
I2C_TXD = 0xC
I2C_RXD = 0xE
I2C_INTF = 0x10
I2C_INTE = 0x12

# RTCA: CTL +0 (RTCRUN b0, RTCRST b1), SWCTL +6 (SWRUN b0, SWRST b4),
# INTF +10 (w1c), INTE +12. INTF: b0 1/32 s, b1 1/8 s, b2 1/4 s, b3 1/2 s,
# b4 1 s, b5 min, b6 hour, b7 day, b12..b14 stopwatch 100/10/1 Hz.
# Only the interrupt side is modelled; the Suunto app never reads the time
# counters and keeps its own clock from the 1 s flag.
RTC_BASE = 0x0C0
RTCCTL = 0x0C0
RTCSWCTL = 0x0C6
RTCINTF = 0x0D0
RTCINTE = 0x0D2
RTC_IRQ = 4

# PPORT: Pn at 0x200 + 0x10 * n. +0 DAT (b15..8 out, b7..0 in), +2 IOEN
# (b15..8 input enable, b7..0 output enable), +4 RCTL, +6 INTF (w1c),
# +8 INTCTL (b15..8 falling edge, b7..0 enable), +A CHATEN, +C MODSEL,
# +E FNCSEL. Port interrupts share IRQ2.
PORT_BASE = 0x200
PORT_COUNT = 9
PORT_IRQ = 2
# Console backdoor, not on silicon: u16 write (port << 8) | pin levels.
PIN_INJECT = 0x2FF0

# 24xx512 on i2c0 (Lola P24_16BitAddr: memSize 0x10000, pageSize 0x80).
# 16-bit address, page-wrapping writes, erased to 0xFF. The app's persObj
# records are data + checksum, so 0xFF cells fall back to defaults while
# an all-zero EEPROM would load zeroed settings.
EEPROM_BUS = 0x3C0
EEPROM_ADDR = 0x50
EEPROM_SIZE = 0x10000
EEPROM_PAGE = 0x80
# The console rewrites this to a runtime path; None keeps it in RAM.
EEPROM_FILE = None

# Sensor ID registers by 7-bit address. Every address ACKs; anything not
# seeded reads back what was last written to it (0 at power-up).
# 0x5C/0x5D are the LPS27HHW (handled in lps27_*, not this table).
I2C_SEED = {
    0x14: {0x00: 0x33},   # BMM350 CHIP_ID
    0x15: {0x00: 0x33},
}

# LPS27HHW, the part the app selects when WHO_AM_I is 0xB3 (model 2,
# "LPS27HHW"). Pressure is hPa * 4096, little-endian at 0x28. Temperature
# is degC * 100 at 0x2B. 1013.25 hPa / 25.00 C is sea level on a warm day.
LPS27_ADDRS = (0x5C, 0x5D)
LPS27_PRESS = 4150272
LPS27_TEMP = 2500

# Whole-register writes ignored unless SYSPROT == 0x0096.
PROTECTED = set([
    PWGACTL, CLGSCLK, CLGIOSC, CLGOSC1, CLGOSC3,
    WDT2CLK, WDT2CTL, WDT2CMP,
])

# CLGIOSC.IOSCFQ, from the app's own switch at 0x1FCEE. 2 and 3 fall
# through to the 8 MHz default. 7 is the 20 MHz run clock.
IOSC_HZ = {
    0: 1000000,
    1: 2000000,
    2: 8000000,
    3: 8000000,
    4: 8000000,
    5: 12000000,
    6: 16000000,
    7: 20000000,
}
OSC1_HZ = 32768
# CLGOSC3.OSC3FQ. This die tops out at 20 MHz; 6 and 7 stay there.
OSC3_HZ = [8000000, 8000000, 8000000, 8000000, 12000000, 16000000, 20000000, 20000000]

# CLGINTF write-1-to-clear bits.
INTF_W1C = 0x137


def warn(msg):
    try:
        self.Log(LogLevel.Warning, msg)
    except Exception:
        self.NoisyLog(msg)


def warn_once(key, msg):
    # Polling loops on unmodelled registers would otherwise flood the log.
    if key in warned:
        return
    warned.add(key)
    warn(msg)


def info(msg):
    try:
        self.Log(LogLevel.Info, msg)
    except Exception:
        self.NoisyLog(msg)


def get_machine():
    from Antmicro.Renode.Core import EmulationManager
    emu = EmulationManager.Instance.CurrentEmulation
    found, mach = emu.TryGetMachineForPeripheral(self)
    if not found:
        raise Exception("s1c31 is not registered on a machine")
    return mach


def release_owned_clocks():
    # machine Reset re-runs this script with IsInit and drops the Python
    # handles. The LimitTimers stay on the clock source, still enabled, so
    # every reload stacked another RTC and the 1 Hz flag ran at 2 Hz, 3 Hz, ...
    try:
        cs = get_machine().ClockSource
    except Exception:
        return
    owned = [e for e in cs.GetAllClockEntries() if e.Owner == self]
    for e in owned:
        e.Enabled = False
        cs.TryRemoveClockEntry(e)


def defaults():
    return {
        PWGACTL: 0x0010,   # REGSEL = 1 (VD1 mode0)
        CLGSCLK: 0x0000,   # SYSCLK = IOSC / 1
        CLGOSC: 0x0F01,    # *SLPC = 1, IOSCEN = 1
        CLGIOSC: 0x0004,   # IOSCFQ = 8 MHz
        CLGOSC1: 0x5092,
        CLGOSC3: 0x0036,
        CLGINTF: 0x0000,   # IOSCSTAIF stays 0 even though IOSC is on
        CLGINTE: 0x0000,
        CLGFOUT: 0x0000,
        WDT2CLK: 0x0000,
        WDT2CMP: 0x03FF,
        FLASHC: 0x0000,
        P0DAT: 0x0000,
        P0IOEN: 0x0000,
        RTCCTL: 0x0000,
        RTCSWCTL: 0x0000,
        RTCINTF: 0x0000,
        RTCINTE: 0x0000,
        0x800: 0x0100,     # LCD32BCLK.DBRUN
        0x802: 0x0000,     # LCD32BCTL
        0x804: 0x011F,     # FRMCNT=1, LDUTY=0x1F
        0x806: 0x0100,     # BSTC=1
        0x808: 0x8000,     # EXVCSEL=1
        0x80A: 0x0070,     # SEGREV=1, COMREV=1, DSPREV=1, DSPC=off
        0x80C: 0xFFFF,     # COM0..15 drive enabled
        0x80E: 0xFFFF,     # COM16..31 drive enabled
        0x810: 0x0000,
        0x812: 0x0000,
    }


def reset_regs():
    global sysprot, regs, wdt_mod, wdt_statnmi, wdt_running, wdt_in_handler
    sysprot = 0
    regs = defaults()
    for base in T16:
        for r in (T16_CLK, T16_MOD, T16_CTL, T16_TR, T16_INTF, T16_INTE):
            regs[base + r] = 0
        t = t16_timers.get(base)
        if t is not None:
            t.Enabled = False
        t16_irq(base)
    for base in I2C:
        for r in range(0, I2C_INTE + 2, 2):
            regs[base + r] = 0
        i2c_state[base] = {'phase': 'idle', 'addr': 0, 'rx_hold': 0}
    wdt_mod = 0
    wdt_statnmi = 0
    wdt_running = False
    if wdt_timer is not None:
        wdt_timer.Enabled = False
        wdt_timer.Value = 0


def unlocked():
    return sysprot == PROT_KEY


def iosc_hz():
    return IOSC_HZ.get(regs[CLGIOSC] & 0x7, 8000000)


def osc3_hz():
    return OSC3_HZ[(regs[CLGOSC3] >> 10) & 0x7]


def sysclk_hz():
    # CLGSCLK: CLKSRC[1:0], CLKDIV[1:0] at bits 5:4. IOSC /1../8,
    # OSC1 /1../2, OSC3 /1../16. Reserved dividers stay at /1.
    src = regs[CLGSCLK] & 0x3
    div = (regs[CLGSCLK] >> 4) & 0x3
    if src == 0:
        base, parts = iosc_hz(), (1, 2, 4, 8)
    elif src == 1:
        base, parts = OSC1_HZ, (1, 2, 1, 1)
    elif src == 2:
        base, parts = osc3_hz(), (1, 2, 8, 16)
    else:
        base, parts = iosc_hz(), (1, 1, 1, 1)
    hz = base // parts[div]
    if hz < 1:
        return 1
    return hz


def apply_cpu_clock():
    # PerformanceInMips is an integer and floors at 1, so the 32 kHz run
    # clock still executes about 30x too much code per virtual second. 16
    # was the old fixed value: the host managed about 0.5 MIPS and the
    # watch ran tens of times slower than the wall clock.
    hz = sysclk_hz()
    mips = hz // 1000000
    if mips < 1:
        mips = 1
    if mips > 20:
        mips = 20
    try:
        cpu = node("cpu")
    except Exception:
        return
    if num(cpu.PerformanceInMips) != mips:
        cpu.PerformanceInMips = mips
        info("SYSCLK %d Hz -> %d MIPS" % (hz, mips))
    try:
        node("nvic").SystickFrequency = hz
    except Exception:
        pass


def wdt_freq():
    clk = regs[WDT2CLK]
    src = clk & 0x3
    div = (clk >> 4) & 0x3
    osc = regs[CLGOSC]
    # CLKDIV 0 is the fastest. The manual wants the counter near 256 Hz,
    # which is OSC1/128.
    if src == 0 or src == 2:
        base = iosc_hz() if src == 0 else osc3_hz()
        en = 0x1 if src == 0 else 0x4
        parts = [8192, 16384, 32768, 65536]
    elif src == 1:
        base = OSC1_HZ
        en = 0x2
        parts = [128, 256, 512, 1024]
    else:
        base = OSC1_HZ
        en = 0x8
        parts = [1, 1, 1, 1]
    if (osc & en) == 0:
        return 1
    freq = base // parts[div]
    if freq < 1:
        return 1
    return freq


def ensure_timer(limit, freq):
    global wdt_timer
    if wdt_timer is None:
        from Antmicro.Renode.Peripherals.Timers import LimitTimer
        from Antmicro.Renode.Time import WorkMode, Direction
        mach = get_machine()
        wdt_timer = LimitTimer(
            mach.ClockSource, freq, self, "wdt2", limit, Direction.Ascending,
            False, WorkMode.Periodic, True)
        wdt_timer.LimitReached += on_wdt
        return
    was = wdt_timer.Enabled
    wdt_timer.Enabled = False
    wdt_timer.Frequency = freq
    wdt_timer.Limit = limit
    if was:
        wdt_timer.Enabled = True


def node(name):
    # Machine indexer is the monitor path resolver. CPU and NVIC live under sysbus.
    return get_machine()["sysbus." + name]


def arm_wdt(kick):
    limit = regs[WDT2CMP] & 0x3FF
    if limit < 1:
        limit = 1
    ensure_timer(limit, wdt_freq())
    if kick:
        wdt_timer.Value = 0
    wdt_timer.Enabled = wdt_running


def do_nmi():
    global wdt_statnmi
    wdt_statnmi = 1
    info("WDT2 NMI")
    # Exception 2 is the NMI vector. SetPendingIRQ(2) is the port IRQ.
    node("cpu").RaiseException(2)


def num(v):
    if hasattr(v, 'RawValue'):
        v = v.RawValue
    return int(v)


def do_cpu_reset():
    info("WDT2 reset")
    # cpu.Reset() only arms a vector reload and pauses the core. The reload
    # runs on the way out of reset, which does not happen from a timer
    # callback, so SP/PC are loaded here and the core is resumed.
    reset_regs()
    try:
        nvic = node("nvic")
        cpu = node("cpu")
        bus = cpu.Bus
        nvic.Reset()
        cpu.Reset()
        vtor = num(cpu.VectorTableOffset) & 0xFFFFFFFF
        sp = num(bus.ReadDoubleWord(vtor))
        entry = num(bus.ReadDoubleWord(vtor + 4))
        rv = cpu.PC
        cpu.SP = rv.Create(sp, 32)
        cpu.PC = rv.Create(entry, 32)
        if cpu.IsPaused:
            cpu.Resume()
    except Exception as e:
        info("WDT2 reset failed: " + str(e))
    apply_cpu_clock()


def on_wdt():
    global wdt_in_handler
    if wdt_in_handler:
        return
    wdt_in_handler = True
    try:
        if wdt_timer is not None:
            wdt_timer.Value = 0
        if not wdt_running:
            return
        if wdt_mod == 1:
            do_nmi()
        elif wdt_mod == 2:
            if wdt_statnmi:
                do_cpu_reset()
            else:
                do_nmi()
        else:
            do_cpu_reset()
    finally:
        wdt_in_handler = False


def t16_base(off):
    for base in T16:
        if base <= off <= base + T16_INTE:
            return base
    return None


def t16_freq(base):
    clk = regs[base + T16_CLK]
    src = clk & 0x3
    if src == 0:
        hz = iosc_hz()
    elif src == 2:
        hz = 8000000
    else:
        hz = 32768
    hz >>= (clk >> 4) & 0xF
    if hz < 1:
        return 1
    return hz


def t16_irq(base):
    level = (regs[base + T16_INTF] & regs[base + T16_INTE] & 0x1) != 0
    irq_line(T16[base], level)


def t16_underflow(base):
    regs[base + T16_INTF] |= 0x1
    if regs[base + T16_MOD] & 0x1:
        # TRMD one-shot: the counter stops and PRUN drops.
        regs[base + T16_CTL] &= ~0x100
        t16_timers[base].Enabled = False
    t16_irq(base)


def t16_timer(base):
    global nvic_node
    t = t16_timers.get(base)
    if t is None:
        from Antmicro.Renode.Peripherals.Timers import LimitTimer
        from Antmicro.Renode.Time import WorkMode, Direction
        nvic_node = node("nvic")
        t = LimitTimer(
            get_machine().ClockSource, t16_freq(base), self, "t16_%x" % base,
            0x10000, Direction.Descending, False, WorkMode.Periodic, True)
        t.LimitReached += lambda: t16_underflow(base)
        t16_timers[base] = t
    return t


def t16_write(base, reg, value):
    if reg == T16_CLK:
        regs[base + T16_CLK] = value & 0x01F3
        t = t16_timer(base)
        t.Frequency = t16_freq(base)
    elif reg == T16_MOD:
        regs[base + T16_MOD] = value & 0x1
    elif reg == T16_CTL:
        # MODEN b0, PRESET b1 (reads 0 once TR is in TC), PRUN b8.
        t = t16_timer(base)
        if not (value & 0x1):
            regs[base + T16_CTL] = 0
            t.Enabled = False
            return
        regs[base + T16_CTL] = value & 0x0101
        if value & 0x2:
            t.Limit = regs[base + T16_TR] + 1
            t.Value = t.Limit
        if value & 0x100:
            t.Frequency = t16_freq(base)
            t.Limit = regs[base + T16_TR] + 1
        t.Enabled = (value & 0x100) != 0
    elif reg == T16_TR:
        regs[base + T16_TR] = value
    elif reg == T16_INTF:
        regs[base + T16_INTF] &= ~(value & 0x1)
        t16_irq(base)
    elif reg == T16_INTE:
        regs[base + T16_INTE] = value & 0x1
        t16_irq(base)


def t16_read(base, reg):
    if reg == T16_CTL:
        t = t16_timers.get(base)
        ctl = regs[base + T16_CTL]
        if t is not None and (ctl & 0x100) and (regs[base + T16_MOD] & 0x1):
            # The app's delay helper polls PRUN with PRIMASK set. Each poll
            # costs ~100x its virtual time in IronPython, so let every poll
            # eat 1/16 of the count; nothing keeps time from T16.
            v = num(t.Value) - max(1, num(t.Limit) >> 4)
            if v <= 0:
                t16_underflow(base)
            else:
                t.Value = v
        return regs[base + T16_CTL] & 0xFFFF
    if reg == T16_TC:
        t = t16_timers.get(base)
        if t is None or not (regs[base + T16_CTL] & 0x1):
            return 0
        v = num(t.Value) - 1
        if v < 0:
            return 0
        return v & 0xFFFF
    return regs.get(base + reg, 0) & 0xFFFF


def i2c_base(off):
    for base in I2C:
        if base <= off <= base + I2C_INTE:
            return base
    return None


def eeprom_seed(mem):
    # Production records (Lola prodData), written on the factory line. With
    # record 0x400 or 0x43F blank the app boots into its test menu (boot
    # mode 11 / 10). Field meanings are guesses beyond the two serials. The
    # CRC32 slots at 0x780.. stay erased: the app computes and stores them
    # on first read.
    def put(addr, data):
        for i in range(len(data)):
            mem[addr + i] = data[i]

    def text(s, n):
        return [ord(c) for c in s[:n]] + [0] * (n - min(len(s), n))

    put(0x400, [0x01, 0x01, 0x01, 0x00, 0x01, 0x00, 0x01, 0x00])
    put(0x408, [0xC0, 0x31, 0x74, 0x00, 0x00, 0x01])
    put(0x40E, [0] * 14)
    put(0x41C, text('EMU-PCBA-0001', 35))
    put(0x43F, [0x01, 0x01, 0x01] + [0] * 10)
    put(0x44C, text('EMU000000001', 12))
    put(0x458, [0] * (0x4AE - 0x458))


def eeprom_load():
    # A .NET byte[] so a save is one WriteAllBytes; the log area at 0xD000
    # gets a write every second or so.
    from System import Array, Byte
    from System.IO import File
    if EEPROM_FILE and File.Exists(EEPROM_FILE):
        data = File.ReadAllBytes(EEPROM_FILE)
        if data.Length == EEPROM_SIZE:
            return data
        warn("%s: %d bytes, expected %d; starting erased" % (EEPROM_FILE, data.Length, EEPROM_SIZE))
    mem = Array.CreateInstance(Byte, EEPROM_SIZE)
    for i in range(EEPROM_SIZE):
        mem[i] = 0xFF
    eeprom_seed(mem)
    eeprom_save({'mem': mem})
    return mem


def eeprom_save(dev):
    if not EEPROM_FILE:
        return
    from System.IO import File
    try:
        File.WriteAllBytes(EEPROM_FILE, dev['mem'])
    except Exception as e:
        warn_once(('eeprom', 'save'), "eeprom save failed: %s" % e)


def lps27_reset(dev):
    # CTRL_REG2.IF_ADD_INC (bit 4) is 1 out of reset. BOOT, SWRESET and
    # ONE_SHOT are sticky-0: the app spins on SWRESET and a stuck 1 is a
    # 1 second timeout followed by a dead pressure read.
    regs = [0] * 256
    regs[0x0F] = 0xB3
    regs[0x11] = 0x10
    dev['regs'] = regs


def lps27_peek(dev):
    p = dev['ptr']
    if p == 0x0F:
        return 0xB3
    if p == 0x11:
        return dev['regs'][0x11] & ~0x85
    if p == 0x27:
        # P_DA | T_DA. ODR 0 still reports ready; the app only checks the bit.
        return 0x03
    if p == 0x28:
        return LPS27_PRESS & 0xFF
    if p == 0x29:
        return (LPS27_PRESS >> 8) & 0xFF
    if p == 0x2A:
        return (LPS27_PRESS >> 16) & 0xFF
    if p == 0x2B:
        return LPS27_TEMP & 0xFF
    if p == 0x2C:
        return (LPS27_TEMP >> 8) & 0xFF
    return dev['regs'][p]


def lps27_store(dev, reg, byte):
    if reg == 0x0F or reg == 0x27 or (0x28 <= reg <= 0x2C):
        return
    if reg == 0x11:
        if byte & 0x04:
            lps27_reset(dev)
            return
        # Keep IF_ADD_INC. Drop BOOT (bit 7) and ONE_SHOT (bit 0).
        dev['regs'][0x11] = (byte & ~0x85) | 0x10
        return
    dev['regs'][reg] = byte & 0xFF


def i2c_dev(base, addr):
    key = (base, addr)
    dev = i2c_devs.get(key)
    if dev is None:
        if base == EEPROM_BUS and addr == EEPROM_ADDR:
            dev = {'kind': 'eeprom', 'mem': eeprom_load(), 'ptr': 0}
        elif addr in LPS27_ADDRS:
            dev = {'kind': 'lps27', 'regs': [0] * 256, 'ptr': 0}
            lps27_reset(dev)
        else:
            # Register file with a 1-byte pointer. Seeded IDs: I2C_SEED.
            dev = {'kind': 'regs', 'regs': [0] * 256, 'ptr': 0}
            for reg, val in I2C_SEED.get(addr, {}).items():
                dev['regs'][reg] = val
        dev['n'] = 0
        dev['t0'] = 0
        i2c_devs[key] = dev
    return dev


def dev_peek(dev):
    if dev['kind'] == 'eeprom':
        return int(dev['mem'][dev['ptr']])
    if dev['kind'] == 'lps27':
        return lps27_peek(dev)
    return dev['regs'][dev['ptr']]


def dev_next(dev):
    if dev['kind'] == 'eeprom':
        dev['ptr'] = (dev['ptr'] + 1) & (EEPROM_SIZE - 1)
    else:
        dev['ptr'] = (dev['ptr'] + 1) & 0xFF
    dev['n'] += 1


def dev_write(base, addr, dev, byte):
    n = dev['n']
    dev['n'] = n + 1
    if dev['kind'] == 'eeprom':
        if n == 0:
            dev['ptr'] = (byte << 8) | (dev['ptr'] & 0xFF)
        elif n == 1:
            dev['ptr'] = (dev['ptr'] & 0xFF00) | byte
            dev['t0'] = dev['ptr']
        else:
            p = dev['ptr']
            dev['mem'][p] = byte
            dev['ptr'] = (p & ~(EEPROM_PAGE - 1)) | ((p + 1) & (EEPROM_PAGE - 1))
        return
    if n == 0:
        dev['ptr'] = byte
        return
    if dev['kind'] == 'lps27':
        lps27_store(dev, dev['ptr'], byte)
    else:
        dev['regs'][dev['ptr']] = byte
    i2c_note(base, 'W', addr, dev['ptr'], byte)
    dev['ptr'] = (dev['ptr'] + 1) & 0xFF


def i2c_end(base):
    # Closes the current leg at a (repeated) START, STOP or reset.
    st = i2c_state[base]
    dev = st.get('dev')
    st['dev'] = None
    st['rx_hold'] = 0
    if dev is None or dev['kind'] != 'eeprom':
        return
    if st['phase'] == 'write' and dev['n'] > 2:
        eeprom_note('W', dev['t0'], dev['n'] - 2)
        eeprom_save(dev)
    elif st['phase'] == 'read' and dev['n'] > 0:
        eeprom_note('R', dev['t0'], dev['n'])


def eeprom_note(kind, start, n):
    key = ('eeprom', kind, start, n)
    if key in i2c_seen:
        return
    i2c_seen.add(key)
    info("eeprom %s 0x%04x len %d" % (kind, start, n))


def i2c_note(base, kind, addr, reg, val):
    key = (base, kind, addr, reg)
    if key in i2c_seen:
        return
    i2c_seen.add(key)
    info("%s %s 0x%02x reg 0x%02x = 0x%02x" % (I2C[base], kind, addr, reg, val))


def i2c_write(base, reg, value):
    st = i2c_state[base]
    if reg == I2C_CTL:
        regs[base + I2C_CTL] = value & 0x0031
        if value & 0x2:
            i2c_end(base)
            st['phase'] = 'idle'
            regs[base + I2C_INTF] = 0
        if not (value & 0x1):
            i2c_end(base)
            st['phase'] = 'idle'
            return
        if value & 0x4:
            # (Repeated) START: next TXD byte is the address.
            i2c_end(base)
            st['phase'] = 'addr'
            regs[base + I2C_INTF] |= 0x09
        if value & 0x8:
            i2c_end(base)
            st['phase'] = 'idle'
            regs[base + I2C_INTF] |= 0x10
        return
    if reg == I2C_TXD:
        regs[base + I2C_TXD] = value & 0xFF
        flags = 0x81
        if st['phase'] == 'addr':
            st['addr'] = (value >> 1) & 0x7F
            dev = i2c_dev(base, st['addr'])
            st['dev'] = dev
            dev['n'] = 0
            dev['t0'] = dev['ptr']
            if value & 1:
                st['phase'] = 'read'
                regs[base + I2C_RXD] = dev_peek(dev)
                # RBFIF stays clear across the driver's NACK check and the
                # stale-byte flush in i2cTransfer (0x1e4bc). Arming it on
                # this write made that flush throw away WHO_AM_I, so the
                # LPS27 probe never saw 0xB3 and the altimeter took the
                # null-driver path.
                st['rx_hold'] = 2
            else:
                st['phase'] = 'write'
                st['rx_hold'] = 0
        elif st['phase'] == 'write':
            dev_write(base, st['addr'], st['dev'], value & 0xFF)
        regs[base + I2C_INTF] |= flags
        return
    if reg == I2C_INTF:
        regs[base + I2C_INTF] &= ~(value & 0xFC)
        return
    regs[base + reg] = value


def i2c_read(base, reg):
    st = i2c_state[base]
    if reg == I2C_RXD:
        val = regs[base + I2C_RXD]
        if st['phase'] == 'read':
            dev = st['dev']
            if dev['kind'] == 'regs':
                i2c_note(base, 'R', st['addr'], dev['ptr'], val)
            dev_next(dev)
            regs[base + I2C_RXD] = dev_peek(dev)
            regs[base + I2C_INTF] |= 0x82
        return val
    if reg == I2C_INTF:
        hold = st.get('rx_hold', 0)
        if hold:
            st['rx_hold'] = hold - 1
            if hold == 1:
                regs[base + I2C_INTF] |= 0x02
        v = regs[base + I2C_INTF]
        if hold:
            v &= ~0x02
        if regs[base + I2C_CTL] & 0x1:
            v |= 0x01
        return v
    return regs.get(base + reg, 0) & 0xFFFF


def irq_line(n, level):
    global nvic_node
    if nvic_node is None:
        try:
            nvic_node = node("nvic")
        except Exception:
            return
    nvic_node.OnGPIO(n, level)


def rtc_irq():
    irq_line(RTC_IRQ, (regs[RTCINTF] & regs[RTCINTE]) != 0)


def rtc_flag(bits):
    regs[RTCINTF] |= bits
    if regs[RTCINTE] & bits:
        rtc_irq()


def rtc_tick128():
    global rtc_n
    if not (regs[RTCCTL] & 0x1):
        return
    rtc_n += 1
    n = rtc_n
    bits = 0
    if n % 4 == 0:
        bits |= 0x01
    if n % 16 == 0:
        bits |= 0x02
    if n % 32 == 0:
        bits |= 0x04
    if n % 64 == 0:
        bits |= 0x08
    if n % 128 == 0:
        bits |= 0x10
        s = n // 128
        if s % 60 == 0:
            bits |= 0x20
        if s % 3600 == 0:
            bits |= 0x40
        if s % 86400 == 0:
            bits |= 0x80
    if bits:
        rtc_flag(bits)


def rtc_tick100():
    global rtc_sw
    if not (regs[RTCSWCTL] & 0x1):
        return
    rtc_sw += 1
    bits = 0x1000
    if rtc_sw % 10 == 0:
        bits |= 0x2000
    if rtc_sw % 100 == 0:
        bits |= 0x4000
    rtc_flag(bits)


def rtc_timers():
    global rtc_t128, rtc_t100
    if rtc_t128 is not None:
        return
    from Antmicro.Renode.Peripherals.Timers import LimitTimer
    from Antmicro.Renode.Time import WorkMode, Direction
    cs = get_machine().ClockSource
    rtc_t128 = LimitTimer(cs, 1024, self, "rtc128", 8, Direction.Descending,
                          True, WorkMode.Periodic, True)
    rtc_t128.LimitReached += rtc_tick128
    rtc_t100 = LimitTimer(cs, 1000, self, "rtc100", 10, Direction.Descending,
                          True, WorkMode.Periodic, True)
    rtc_t100.LimitReached += rtc_tick100


def rtc_write(off, value):
    global rtc_n, rtc_sw
    rtc_timers()
    if off == RTCCTL:
        if value & 0x2:
            rtc_n = 0
        regs[RTCCTL] = value & ~0x2 & 0xFFFF
    elif off == RTCSWCTL:
        if value & 0x10:
            rtc_sw = 0
        regs[RTCSWCTL] = value & ~0x10 & 0xFFFF
    elif off == RTCINTF:
        regs[RTCINTF] &= ~value
        rtc_irq()
    elif off == RTCINTE:
        regs[RTCINTE] = value
        rtc_irq()
    else:
        regs[off] = value


def port_of(off):
    if PORT_BASE <= off < PORT_BASE + 0x10 * PORT_COUNT:
        return (off - PORT_BASE) >> 4
    return None


def port_irq():
    level = False
    for n in range(PORT_COUNT):
        b = PORT_BASE + 0x10 * n
        if regs.get(b + 6, 0) & regs.get(b + 8, 0) & 0xFF:
            level = True
            break
    irq_line(PORT_IRQ, level)


def port_read(off):
    n = port_of(off)
    b = PORT_BASE + 0x10 * n
    r = off - b
    if r == 0:
        dat = regs.get(b, 0)
        ioen = regs.get(b + 2, 0)
        oen = ioen & 0xFF
        ien = (ioen >> 8) & 0xFF
        # Output-enabled bits read back the low-byte latch (our test images
        # drive P0 that way); input-enabled bits read the pin.
        low = (dat & oen) | (pins[n] & ien & ~oen)
        return (dat & 0xFF00) | (low & 0xFF)
    return regs.get(off, 0) & 0xFFFF


def port_write(off, value):
    n = port_of(off)
    b = PORT_BASE + 0x10 * n
    if off - b == 6:
        regs[off] = regs.get(off, 0) & ~value & 0xFF
        port_irq()
        return
    regs[off] = value
    if off - b == 8:
        port_irq()


def set_pins(n, level):
    if n < 0 or n >= PORT_COUNT:
        return
    b = PORT_BASE + 0x10 * n
    old = pins[n]
    pins[n] = level & 0xFF
    ien = (regs.get(b + 2, 0) >> 8) & 0xFF
    ctl = regs.get(b + 8, 0)
    fall = (ctl >> 8) & 0xFF
    changed = (old ^ pins[n]) & ien
    falling = changed & old & fall
    rising = changed & pins[n] & ~fall
    hit = (falling | rising) & 0xFF
    if hit:
        regs[b + 6] = regs.get(b + 6, 0) | hit
        port_irq()


def write_clgosc(value):
    old = regs[CLGOSC]
    new = value & 0x0F0F
    regs[CLGOSC] = new
    # Edge 0->1 sets the stable flag. Level-1 at reset must NOT, because
    # IOSCSTAIF reads 0 after reset while IOSCEN is already 1.
    pairs = ((0x1, 0x1), (0x2, 0x2), (0x4, 0x4))
    for en, flag in pairs:
        if (new & en) and not (old & en):
            regs[CLGINTF] |= flag
        elif not (new & en):
            regs[CLGINTF] &= ~flag


def write_wdtctl(value):
    global wdt_mod, wdt_statnmi, wdt_running
    wdt_mod = (value >> 9) & 0x3
    kick = (value & 0x10) != 0
    nibble = value & 0xF
    if kick:
        wdt_statnmi = 0
    if nibble == 0xA:
        wdt_running = False
    else:
        wdt_running = True
    arm_wdt(kick)


def write_u16(off, value):
    global sysprot
    value &= 0xFFFF
    if off == SYSPROT:
        sysprot = value
        return
    if off in PROTECTED and not unlocked():
        warn("protected write ignored at +0x%x value 0x%x" % (off, value))
        return
    if off == CLGOSC:
        write_clgosc(value)
        return
    if off == CLGINTF:
        regs[CLGINTF] &= ~(value & INTF_W1C)
        return
    if off == WDT2CTL:
        write_wdtctl(value)
        return
    if off == WDT2CLK:
        regs[WDT2CLK] = value & 0x0133
        if wdt_running:
            arm_wdt(False)
        return
    if off == WDT2CMP:
        regs[WDT2CMP] = value & 0x03FF
        if wdt_running:
            arm_wdt(False)
        return
    if off == CLGIOSC:
        # IOSCSTM (bit 4) auto-trim completes at once: the bit self-clears
        # and IOSCTEDIF is raised. Silicon needs OSC1 running for this.
        regs[CLGIOSC] = value & 0x0007
        if value & 0x10:
            regs[CLGINTF] |= 0x10
        apply_cpu_clock()
        if wdt_running:
            arm_wdt(False)
        return
    if port_of(off) is not None:
        port_write(off, value)
        return
    if RTCCTL <= off <= RTCINTE:
        rtc_write(off, value)
        return
    if off == PIN_INJECT:
        set_pins((value >> 8) & 0xFF, value & 0xFF)
        return
    if off == LCDCTL:
        old = regs.get(LCDCTL, 0)
        regs[LCDCTL] = value & LCD_MASK[LCDCTL]
        if (value & 0x1) and not (old & 0x1):
            info("LCD32B enabled")
        elif (old & 0x1) and not (value & 0x1):
            # MODEN 1->0 turns the display off and clears DSPC.
            regs[LCDDSP] &= ~0x3
        return
    if off == LCDINTF:
        regs[LCDINTF] &= ~(value & 0x1)
        return
    if off in LCD_MASK:
        regs[off] = value & LCD_MASK[off]
        return
    base = t16_base(off)
    if base is not None:
        t16_write(base, off - base, value)
        return
    base = i2c_base(off)
    if base is not None:
        i2c_write(base, off - base, value)
        return
    if off not in regs and not (LCD_BASE <= off <= LCD_END):
        warn_once(('w', off), "MMIO write +0x%x = 0x%x (stored, reads back)" % (off, value))
    regs[off] = value
    if off == CLGSCLK or off == CLGOSC3:
        apply_cpu_clock()


def read_u16(off):
    if off == SYSPROT:
        return sysprot
    if off == WDT2CTL:
        run_bits = 0x0 if wdt_running else 0xA
        return ((wdt_mod & 0x3) << 9) | ((wdt_statnmi & 1) << 8) | run_bits
    if port_of(off) is not None:
        return port_read(off)
    if off == PIN_INJECT:
        return 0
    base = t16_base(off)
    if base is not None:
        return t16_read(base, off - base)
    base = i2c_base(off)
    if base is not None:
        return i2c_read(base, off - base)
    if off in regs:
        return regs[off] & 0xFFFF
    warn_once(('r', off), "MMIO read +0x%x (never written, 0)" % off)
    return 0


def access(off, length, write, value):
    if length == 4:
        if write:
            write_u16(off, value & 0xFFFF)
            write_u16(off + 2, (value >> 16) & 0xFFFF)
            return 0
        lo = read_u16(off)
        hi = read_u16(off + 2)
        return lo | (hi << 16)
    if length == 1:
        aligned = off & ~1
        shift = 8 if (off & 1) else 0
        cur = read_u16(aligned)
        if write:
            merged = (cur & ~(0xFF << shift)) | ((value & 0xFF) << shift)
            write_u16(aligned, merged)
            return 0
        return (cur >> shift) & 0xFF
    if off & 1:
        warn("unaligned MMIO at +0x%x" % off)
    if write:
        write_u16(off, value)
        return 0
    return read_u16(off)


if request.IsInit:
    release_owned_clocks()
    wdt_timer = None
    wdt_in_handler = False
    t16_timers = {}
    nvic_node = None
    warned = set()
    i2c_state = {}
    i2c_devs = {}
    i2c_seen = set()
    pins = [0xFF] * PORT_COUNT
    rtc_t128 = None
    rtc_t100 = None
    rtc_n = 0
    rtc_sw = 0
    reset_regs()
    apply_cpu_clock()
elif request.IsRead:
    request.Value = access(request.Offset, request.Length, False, 0)
elif request.IsWrite:
    access(request.Offset, request.Length, True, request.Value)
