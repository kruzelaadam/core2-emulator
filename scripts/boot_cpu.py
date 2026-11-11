# machine Reset does not clear MappedMemory (Reset() is a no-op after the
# machine has run), and cpu.Reset() parks the core at PC 0 with the vector
# reload pending. wipe_mem() before LoadELF / LoadBinary. boot_cpu() after
# cpu Reset.

def as_int(val):
    if hasattr(val, 'RawValue'):
        val = val.RawValue
    return int(val)

def wipe_mem():
    for name in ('flash', 'xip', 'sram', 'displayRam', 'usbRam'):
        monitor.Machine['sysbus.' + name].ZeroAll()

def boot_cpu(run):
    cpu = monitor.Machine['sysbus.cpu']
    bus = cpu.Bus
    vtor = as_int(cpu.VectorTableOffset) & 0xFFFFFFFF
    sp = as_int(bus.ReadDoubleWord(vtor))
    entry = as_int(bus.ReadDoubleWord(vtor + 4))
    rv = cpu.PC
    cpu.SP = rv.Create(sp, 32)
    cpu.PC = rv.Create(entry, 32)
    if run and cpu.IsPaused:
        cpu.Resume()
