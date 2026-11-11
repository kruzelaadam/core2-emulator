# Paces virtual time to the host clock for the PHP console. Renode only
# syncs to the host while the CPU sleeps; a busy loop runs as fast as the
# host allows. The timer callback runs on the CPU thread, so sleeping there
# throttles the core. Robot tests never load this file.
import time
from Antmicro.Renode.Peripherals.Timers import LimitTimer
from Antmicro.Renode.Time import Direction, WorkMode

try:
    rt
except NameError:
    rt = {'on': False, 'v0': None, 'w0': None, 'timer': None}

def _rt_tick():
    # The master time source already sleeps so virtual time cannot pass
    # wall time (AdvanceImmediately = False). Sleeping here as well held
    # the RTC at about 0.2x, so the 1 Hz flag never matched a real second.
    return

def rt_install(on):
    rt['on'] = bool(on)
    rt['v0'] = None
    from Antmicro.Renode.Core import EmulationManager
    EmulationManager.Instance.CurrentEmulation.MasterTimeSource.AdvanceImmediately = not on
    if rt['timer'] is not None:
        rt['timer'].Enabled = False
