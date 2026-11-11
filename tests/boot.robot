*** Settings ***
Suite Setup                   Setup
Suite Teardown                Teardown
Test Setup                    Reset Emulation
Test Teardown                 Test Teardown
Resource                      ${RENODEKEYWORDS}

*** Variables ***
${PLATFORM}                   ${CURDIR}${/}..${/}platform${/}s1c31w74.repl
${HAPPY}                      ${CURDIR}${/}..${/}fw${/}happy.elf
${WDT}                        ${CURDIR}${/}..${/}fw${/}wdt_reset.elf
${CORE}                       ${CURDIR}${/}..${/}fw${/}core_demo.elf

*** Keywords ***
Create Machine
    [Arguments]    ${elf}
    Execute Command           mach create
    Execute Command           machine LoadPlatformDescription @${PLATFORM}
    Execute Command           sysbus LoadELF @${elf}

Magic Is
    [Arguments]    ${expected}
    ${magic}=    Execute Command    sysbus ReadDoubleWord 0x20000000
    Should Contain            ${magic}    ${expected}

Word Is
    [Arguments]    ${addr}    ${pattern}
    ${val}=    Execute Command    sysbus ReadDoubleWord ${addr}
    Should Match Regexp       ${val}    (?i)${pattern}(\\s|$)

*** Test Cases ***
Clock WDT GPIO And LCD RAM
    Create Machine            ${HAPPY}
    Start Emulation
    Wait Until Keyword Succeeds    20s    100ms    Magic Is    C0FFEE01
    ${disp}=    Execute Command    sysbus ReadWord 0x20200000
    Should Contain            ${disp}    A55A
    ${p0}=    Execute Command    sysbus ReadWord 0x40000200
    Should Match Regexp       ${p0}    (?i)0x0*1(\\s|$)
    # The handler writes the magic word and then nop-spins. A non-zero PC
    # plus the magic word is the stop.
    ${pc}=    Execute Command    cpu PC
    Should Not Match Regexp   ${pc}    (?i)0x0+(\\s|$)
    ${magic}=    Execute Command    sysbus ReadDoubleWord 0x20000000
    Should Not Contain        ${magic}    DEAD

Core Demo Drives LCD32B
    Create Machine            ${CORE}
    Start Emulation
    # Frame 0 goes to area 1, frame 1 to area 0. SEG60 carries battery
    # (COM0), ALTI (COM1) and the up hint (COM2) and nothing else, in both.
    Wait Until Keyword Succeeds    20s    200ms    Word Is    0x202000F0    0x0*7
    Word Is                   0x202002F0    0x0*7
    ${ctl}=    Execute Command    sysbus ReadWord 0x40000802
    Should Match Regexp       ${ctl}    (?i)0x0*1(\\s|$)
    ${tim}=    Execute Command    sysbus ReadWord 0x40000804
    Should Match Regexp       ${tim}    (?i)0x0*11F(\\s|$)
    ${dsp}=    Execute Command    sysbus ReadWord 0x4000080A
    Should Match Regexp       ${dsp}    (?i)0x0*7[15](\\s|$)

Watchdog Reset
    Create Machine            ${WDT}
    Start Emulation
    Wait Until Keyword Succeeds    20s    100ms    Magic Is    C0FFEE02
    ${boots}=    Execute Command    sysbus ReadDoubleWord 0x20000004
    Should Match Regexp       ${boots}    (?i)0x0*2(\\s|$)
    ${magic}=    Execute Command    sysbus ReadDoubleWord 0x20000000
    Should Not Contain        ${magic}    DEAD
