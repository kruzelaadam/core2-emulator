#include "regs.h"

/* Suunto Core 2 style face on LCD32B: 1/32 duty, 72 SEG x 32 COM, double-
 * buffered through LCD32BDSP.DSPAR. Band sizes are counted off product
 * photos: all three bands are 49 dots wide, 7 + 18 + 7 = 32 rows, which is
 * exactly LCD32B's 32 COM. The wiring itself is unknown; this placeholder
 * is what web/core_glass.js draws:
 *
 *   top band      49 x 7 dots   SEG 0+x, COM 0+y
 *   big band      49 x 18 dots  SEG 0+x, COM 7+y
 *   bottom band   49 x 7 dots   SEG 0+x, COM 25+y
 *   COM0 SEG 60..66  battery, weather, sun, divider, lock, alarm, bluetooth
 *   COM1 SEG 60..64  ALTI, BARO, COMP, DIVE, menu
 *   COM2 SEG 60..64  button hints up, next, down, back, mark above SUUNTO
 *   COM3 SEG 60..63  underline under ALTI, BARO, COMP, DIVE
 *
 * Display area n is at 0x20200000 + 0x200 * n, one 32-bit word per SEG,
 * bit n = COMn (SEGREV = COMREV = 1). One frame per ~1 s of virtual time.
 * Cortex-M0+ has no divider and the images link without libgcc, so there is
 * no / or % on variables. */

#define AREA(n) ((volatile uint32_t *)(0x20200000u + 0x200u * (n)))
#define NSEG 72
#define BAND_W 49

struct field {
    unsigned char seg, com, w, h;
};

static const struct field top = { 0, 0, BAND_W, 7 };
static const struct field big = { 0, 7, BAND_W, 18 };
static const struct field bottom = { 0, 25, BAND_W, 7 };

#define ICON(seg, com) (((uint32_t)(seg) << 8) | (com))
#define I_BATTERY ICON(60, 0)
#define I_DIVIDER ICON(63, 0)
#define I_LOCK ICON(64, 0)
#define I_ALARM ICON(65, 0)
#define I_BT ICON(66, 0)
#define I_ALTI ICON(60, 1)
#define I_BARO ICON(61, 1)
#define I_COMP ICON(62, 1)
#define I_DIVE ICON(63, 1)
#define I_MENU ICON(64, 1)
#define I_UP ICON(60, 2)
#define I_NEXT ICON(61, 2)
#define I_DOWN ICON(62, 2)
#define I_BACK ICON(63, 2)
#define I_MARK ICON(64, 2)
#define I_UNDER(n) ICON(60 + (n), 3)

struct glyph {
    char ch;
    unsigned char w;
    unsigned char rows[7];
};

/* Top/bottom bands: squared 4 x 7, narrow 1, rows top to bottom,
 * bit (w-1-x) = column x. */
static const struct glyph font4[] = {
    { ' ', 2, { 0, 0, 0, 0, 0, 0, 0 } },
    { '.', 1, { 0, 0, 0, 0, 0, 0, 1 } },
    { ':', 1, { 0, 0, 1, 0, 1, 0, 0 } },
    { '0', 4, { 0b1111, 0b1001, 0b1001, 0b1001, 0b1001, 0b1001, 0b1111 } },
    { '1', 2, { 0b11, 0b01, 0b01, 0b01, 0b01, 0b01, 0b01 } },
    { '2', 4, { 0b1111, 0b0001, 0b0001, 0b1111, 0b1000, 0b1000, 0b1111 } },
    { '3', 4, { 0b1111, 0b0001, 0b0001, 0b0111, 0b0001, 0b0001, 0b1111 } },
    { '4', 4, { 0b1001, 0b1001, 0b1001, 0b1111, 0b0001, 0b0001, 0b0001 } },
    { '5', 4, { 0b1111, 0b1000, 0b1000, 0b1111, 0b0001, 0b0001, 0b1111 } },
    { '6', 4, { 0b1111, 0b1000, 0b1000, 0b1111, 0b1001, 0b1001, 0b1111 } },
    { '7', 4, { 0b1111, 0b0001, 0b0001, 0b0001, 0b0001, 0b0001, 0b0001 } },
    { '8', 4, { 0b1111, 0b1001, 0b1001, 0b1111, 0b1001, 0b1001, 0b1111 } },
    { '9', 4, { 0b1111, 0b1001, 0b1001, 0b1111, 0b0001, 0b0001, 0b1111 } },
    { 'C', 4, { 0b1111, 0b1000, 0b1000, 0b1000, 0b1000, 0b1000, 0b1111 } },
    { 'E', 4, { 0b1111, 0b1000, 0b1000, 0b1111, 0b1000, 0b1000, 0b1111 } },
    { 'N', 5, { 0b10001, 0b11001, 0b10101, 0b10011, 0b10001, 0b10001, 0b10001 } },
    { 'O', 4, { 0b1111, 0b1001, 0b1001, 0b1001, 0b1001, 0b1001, 0b1111 } },
    { 'R', 4, { 0b1111, 0b1001, 0b1001, 0b1111, 0b1010, 0b1001, 0b1001 } },
    { 'S', 4, { 0b1111, 0b1000, 0b1000, 0b1111, 0b0001, 0b0001, 0b1111 } },
    { 'T', 5, { 0b11111, 0b00100, 0b00100, 0b00100, 0b00100, 0b00100, 0b00100 } },
    { 'U', 4, { 0b1001, 0b1001, 0b1001, 0b1001, 0b1001, 0b1001, 0b1111 } },
    { 'W', 5, { 0b10001, 0b10001, 0b10001, 0b10101, 0b10101, 0b10101, 0b11111 } },
    { 'a', 4, { 0, 0, 0b1111, 0b0001, 0b1111, 0b1001, 0b1111 } },
    { 'd', 4, { 0b0001, 0b0001, 0b1111, 0b1001, 0b1001, 0b1001, 0b1111 } },
    { 'e', 4, { 0, 0, 0b1111, 0b1001, 0b1111, 0b1000, 0b1111 } },
    { 'm', 5, { 0, 0, 0b11111, 0b10101, 0b10101, 0b10101, 0b10101 } },
    { 'p', 4, { 0, 0, 0b1111, 0b1001, 0b1111, 0b1000, 0b1000 } },
    { 0, 0, { 0 } },
};

/* Units in the big band ("hPa" in the photo): squared 3 x 7. */
static const struct glyph font3[] = {
    { 'P', 3, { 0b111, 0b101, 0b101, 0b111, 0b100, 0b100, 0b100 } },
    { 'a', 3, { 0, 0, 0b111, 0b001, 0b111, 0b101, 0b111 } },
    { 'h', 3, { 0b100, 0b100, 0b111, 0b101, 0b101, 0b101, 0b101 } },
    { 'm', 5, { 0, 0, 0b11111, 0b10101, 0b10101, 0b10101, 0b10101 } },
    { 'p', 3, { 0, 0, 0b111, 0b101, 0b111, 0b100, 0b100 } },
    { '\001', 3, { 0b111, 0b101, 0b111, 0, 0, 0, 0 } }, /* degree sign */
    { 0, 0, { 0 } },
};

/* Big band digits: 7 x 18, 2-dot strokes, 7-segment shapes. */
struct rect {
    unsigned char x, y, w, h;
};

static const struct rect bigseg[7] = {
    { 0, 0, 7, 2 },  /* a */
    { 5, 0, 2, 10 }, /* b */
    { 5, 8, 2, 10 }, /* c */
    { 0, 16, 7, 2 }, /* d */
    { 0, 8, 2, 10 }, /* e */
    { 0, 0, 2, 10 }, /* f */
    { 0, 8, 7, 2 },  /* g */
};

static const unsigned char seg7[10] = {
    0x3F, 0x06, 0x5B, 0x4F, 0x66, 0x6D, 0x7D, 0x07, 0x7F, 0x6F,
};

static uint32_t frame[NSEG];
static unsigned char graph[BAND_W];
static uint32_t lfsr;

static void dot(const struct field *f, uint32_t x, uint32_t y)
{
    if (x < f->w && y < f->h) {
        frame[f->seg + x] |= 1u << (f->com + y);
    }
}

static void fill(const struct field *f, uint32_t x0, uint32_t y0, uint32_t w, uint32_t h)
{
    uint32_t x, y;

    for (y = 0; y < h; y++) {
        for (x = 0; x < w; x++) {
            dot(f, x0 + x, y0 + y);
        }
    }
}

static void icon(uint32_t id)
{
    frame[id >> 8] |= 1u << (id & 0xFF);
}

static const struct glyph *find_glyph(const struct glyph *font, char ch)
{
    for (; font->w; font++) {
        if (font->ch == ch) {
            return font;
        }
    }
    return 0;
}

static uint32_t text_width(const struct glyph *font, const char *s)
{
    const struct glyph *g;
    uint32_t w = 0;

    for (; *s; s++) {
        g = find_glyph(font, *s);
        w += (g ? g->w : 0) + (s[1] ? 1 : 0);
    }
    return w;
}

static void text_at(const struct field *f, const struct glyph *font, uint32_t x, uint32_t y, const char *s)
{
    const struct glyph *g;
    uint32_t r, c;

    for (; *s; s++) {
        g = find_glyph(font, *s);
        if (!g) {
            continue;
        }
        for (r = 0; r < 7; r++) {
            for (c = 0; c < g->w; c++) {
                if (g->rows[r] & (1u << (g->w - 1 - c))) {
                    dot(f, x + c, y + r);
                }
            }
        }
        x += g->w + 1;
    }
}

static void text_center(const struct field *f, const char *s)
{
    uint32_t w = text_width(font4, s);

    text_at(f, font4, w < f->w ? (f->w - w) >> 1 : 0, 0, s);
}

static uint32_t big_width(char ch)
{
    return ch == '1' ? 4 : ch == ':' ? 2 : 7;
}

static void big_char(uint32_t x0, char ch)
{
    const struct rect *r;
    uint32_t s, m;

    if (ch == '1') {
        fill(&big, x0, 0, 2, 2);
        fill(&big, x0 + 2, 0, 2, 18);
        return;
    }
    if (ch == ':') {
        fill(&big, x0, 5, 2, 2);
        fill(&big, x0, 11, 2, 2);
        return;
    }
    m = ch == '-' ? 0x40 : (ch >= '0' && ch <= '9') ? seg7[ch - '0'] : 0;
    for (s = 0; s < 7; s++) {
        if (m & (1u << s)) {
            r = &bigseg[s];
            fill(&big, x0 + r->x, r->y, r->w, r->h);
        }
    }
}

/* Value right-aligned to column 34, unit right-aligned to column 48 with
 * its baseline on the bottom row, as in the BARO photo ("1020 hPa"). */
static void big_value(const char *v, const char *unit)
{
    const char *p;
    uint32_t w = 0;
    uint32_t x;

    for (p = v; *p; p++) {
        w += big_width(*p) + (p[1] ? 1 : 0);
    }
    x = 35 - w;
    for (p = v; *p; p++) {
        big_char(x, *p);
        x += big_width(*p) + 1;
    }
    text_at(&big, font3, BAND_W - text_width(font3, unit), 11, unit);
}

static void draw_graph(void)
{
    uint32_t x, y;

    for (x = 0; x < BAND_W; x++) {
        for (y = 7 - graph[x]; y < 7; y++) {
            dot(&bottom, x, y);
        }
    }
}

static void graph_step(void)
{
    uint32_t i, bit, v;

    for (i = 0; i + 1 < BAND_W; i++) {
        graph[i] = graph[i + 1];
    }
    bit = ((lfsr >> 0) ^ (lfsr >> 2) ^ (lfsr >> 3) ^ (lfsr >> 5)) & 1u;
    lfsr = (lfsr >> 1) | (bit << 15);
    v = graph[BAND_W - 2];
    if ((lfsr & 3) == 0 && v < 7) {
        v++;
    } else if ((lfsr & 3) == 1 && v > 1) {
        v--;
    }
    graph[BAND_W - 1] = (unsigned char)v;
}

struct clock {
    uint32_t h10, h1, m10, m1, s10, s1;
};

static void clock_tick(struct clock *c)
{
    if (++c->s1 < 10) {
        return;
    }
    c->s1 = 0;
    if (++c->s10 < 6) {
        return;
    }
    c->s10 = 0;
    if (++c->m1 < 10) {
        return;
    }
    c->m1 = 0;
    if (++c->m10 < 6) {
        return;
    }
    c->m10 = 0;
    if (c->h10 == 2 && c->h1 == 3) {
        c->h10 = 0;
        c->h1 = 0;
    } else if (++c->h1 == 10) {
        c->h1 = 0;
        c->h10++;
    }
}

/* "h:mm" in 12 h form into p, returns 1 for pm. */
static uint32_t fmt12(char *p, uint32_t h, const struct clock *c)
{
    uint32_t pm = h >= 12;

    if (pm) {
        h -= 12;
    }
    if (h == 0) {
        h = 12;
    }
    if (h >= 10) {
        *p++ = '1';
        h -= 10;
    }
    *p++ = (char)('0' + h);
    *p++ = ':';
    *p++ = (char)('0' + c->m10);
    *p++ = (char)('0' + c->m1);
    *p = 0;
    return pm;
}

enum { MODE_TIME, MODE_ALTI, MODE_BARO, MODE_COMP, NMODES };

static const unsigned char mode_secs[NMODES] = { 6, 5, 5, 5 };

static void draw(const struct clock *c, uint32_t mode, uint32_t splash)
{
    char buf[12];
    char hhmm[6];
    uint32_t i, h, pm;

    for (i = 0; i < NSEG; i++) {
        frame[i] = 0;
    }
    h = c->h10 * 10 + c->h1;
    hhmm[0] = (char)('0' + c->h10);
    hhmm[1] = (char)('0' + c->h1);
    hhmm[2] = ':';
    hhmm[3] = (char)('0' + c->m10);
    hhmm[4] = (char)('0' + c->m1);
    hhmm[5] = 0;

    if (splash) {
        text_center(&top, "SUUNTO");
        text_center(&bottom, "CORE 2");
    } else if (mode == MODE_TIME) {
        text_center(&top, "Wed 4.11");
        pm = fmt12(buf, h, c);
        big_value(buf, pm ? "pm" : "am");
        /* Dual time, home + 6 h. */
        h += 6;
        if (h >= 24) {
            h -= 24;
        }
        pm = fmt12(buf, h, c);
        i = 0;
        while (buf[i]) {
            i++;
        }
        buf[i++] = pm ? 'p' : 'a';
        buf[i++] = 'm';
        buf[i] = 0;
        text_center(&bottom, buf);
    } else {
        text_center(&top, hhmm);
        if (mode == MODE_ALTI) {
            big_value("1234", "m");
            draw_graph();
        } else if (mode == MODE_BARO) {
            big_value("1020", "hPa");
            draw_graph();
        } else {
            big_value("275", "\001");
            text_center(&bottom, "WEST");
        }
        icon(I_UNDER(mode - MODE_ALTI));
    }

    icon(I_BATTERY);
    icon(I_DIVIDER);
    icon(I_LOCK);
    icon(I_ALARM);
    if (!(c->s1 & 1)) {
        icon(I_BT);
    }
    icon(I_ALTI);
    icon(I_BARO);
    icon(I_COMP);
    icon(I_DIVE);
    icon(I_MENU);
    if (mode == MODE_TIME) {
        icon(I_UP);
        icon(I_NEXT);
        icon(I_DOWN);
        icon(I_BACK);
    }
    icon(I_MARK);
}

static void lcd_init(void)
{
    LCD32BCLK = 0x0101;   /* DBRUN, OSC1, /1 */
    LCD32BTIM1 = 0x011F;  /* FRMCNT 1, 1/32 duty */
    LCD32BTIM2 = 0x0100;
    LCD32BPWR = 0x0811;   /* LC 8, booster, regulator */
    LCD32BCOMC0 = 0xFFFF;
    LCD32BCOMC1 = 0xFFFF;
    LCD32BCTL = 0x0001;   /* MODEN */
    LCD32BDSP = 0x0071;   /* SEGREV, COMREV, DSPREV normal, area 0, on */
}

static void present(void)
{
    uint32_t back = ((LCD32BDSP >> 2) & 1u) ^ 1u;
    volatile uint32_t *dst = AREA(back);
    uint32_t i;

    for (i = 0; i < NSEG; i++) {
        dst[i] = frame[i];
    }
    LCD32BDSP = (uint16_t)((LCD32BDSP & ~0x4u) | (back << 2));
}

void Reset_Handler(void)
{
    struct clock c = { 1, 0, 0, 8, 0, 0 };
    uint32_t mode = MODE_TIME;
    uint32_t dwell = 0;
    uint32_t splash = 2;
    uint32_t i;

    P0IOEN = 0x0001;
    P0DAT = 0x0000;
    *TOGGLES = 0;
    lfsr = 0xACE1u;
    for (i = 0; i < BAND_W; i++) {
        graph[i] = 3;
    }
    for (i = 0; i < BAND_W; i++) {
        graph_step();
    }
    lcd_init();

    for (;;) {
        draw(&c, mode, splash);
        present();
        P0DAT = (uint16_t)(P0DAT ^ 0x0001);
        *TOGGLES += 1;

        /* ~1 s at PerformanceInMips 16, same loop as blink.c. */
        for (i = 0; i < 4000000u; i++) {
            __asm volatile("nop");
        }
        clock_tick(&c);
        graph_step();
        if (splash) {
            splash--;
        } else if (++dwell == mode_secs[mode]) {
            dwell = 0;
            if (++mode == NMODES) {
                mode = MODE_TIME;
            }
        }
    }
}
