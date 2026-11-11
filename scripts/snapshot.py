# KEY=VALUE dump for the PHP console. Monitor command:
#   python "execfile(r'.../snapshot.py')"
cpu = monitor.Machine['sysbus.cpu']
bus = cpu.Bus

def emit(key, val):
    print '%s=%s' % (key, val)

def as_int(val):
    if hasattr(val, 'RawValue'):
        val = val.RawValue
    return int(val)

def hex32(val):
    return '%08X' % (as_int(val) & 0xFFFFFFFF)

def read16(addr):
    return as_int(bus.ReadWord(addr)) & 0xFFFF

def read32(addr):
    return as_int(bus.ReadDoubleWord(addr)) & 0xFFFFFFFF

want = set(['R0','R1','R2','R3','R4','R5','R6','R7','R8','R9','R10','R11','R12','SP','LR','PC','XPSR','CPSR','CONTROL','PRIMASK'])
for reg in cpu.GetRegisters():
    name = str(reg)
    if name not in want:
        continue
    emit(name, hex32(cpu.GetRegister(reg.Index).RawValue))

emit('HALTED', '0' if cpu.IsStarted and not cpu.IsPaused and not cpu.IsHalted and not monitor.Machine.IsPaused else '1')
try:
    emit('REALTIME', '1' if rt['on'] else '0')
except NameError:
    emit('REALTIME', '0')
for prop in ('SP', 'LR', 'PC'):
    try:
        emit(prop, hex32(getattr(cpu, prop)))
    except:
        pass
try:
    import re
    blob = str(monitor.Machine.ElapsedVirtualTime)
    m = re.search(r'Elapsed Virtual Time: ([0-9:.]+)', blob)
    emit('VTIME', m.group(1) if m else blob.split('\n')[0])
except:
    emit('VTIME', '?')

mmio = [
    ('SYSPROT', 0x40000000),
    ('PWGACTL', 0x40000020),
    ('CLGSCLK', 0x40000040),
    ('CLGOSC', 0x40000042),
    ('CLGIOSC', 0x40000044),
    ('CLGOSC1', 0x40000046),
    ('CLGOSC3', 0x40000048),
    ('CLGINTF', 0x4000004C),
    ('CLGINTE', 0x4000004E),
    ('WDT2CLK', 0x400000A0),
    ('WDT2CTL', 0x400000A2),
    ('WDT2CMP', 0x400000A4),
    ('FLASHC', 0x400001B0),
    ('P0DAT', 0x40000200),
    ('P0IOEN', 0x40000202),
    ('LCDCLK', 0x40000800),
    ('LCDCTL', 0x40000802),
    ('LCDTIM1', 0x40000804),
    ('LCDTIM2', 0x40000806),
    ('LCDPWR', 0x40000808),
    ('LCDDSP', 0x4000080A),
    ('LCDCOMC0', 0x4000080C),
    ('LCDCOMC1', 0x4000080E),
]
for name, addr in mmio:
    emit(name, '%04X' % read16(addr))

emit('MAGIC', hex32(read32(0x20000000)))
emit('BOOTS', hex32(read32(0x20000004)))
emit('TOGGLES', hex32(read32(0x20000008)))
emit('VSP', hex32(read32(0x00000000)))
emit('VRESET', hex32(read32(0x00000004)))

# Both display areas: area 0 at +0x000, area 1 at +0x200, one 32-bit
# word per SEG (bit n = COMn with COMREV=1).
from System import BitConverter
raw = monitor.Machine['sysbus.displayRam'].ReadBytes(0, 0x400)
emit('LCDRAM', BitConverter.ToString(raw).replace('-', ''))
