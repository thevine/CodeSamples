/*
 * Oasis-3 Water Purification System
 *
 * (c) AidGear LLC, 2015
 *
 * Author: Steve Neill <steve@aidgear.com>
 *
 */

/*
 * IMPORTANT:
 * In order to use the vsnprintf() function
 * be sure to link against the following:
 * - libprintf_flt
 * - libm
 * and use the 'vprintf' library
 */

#include <avr/io.h>
#include <stdio.h>
#include <string.h>
#include "main.h"
#include "board.h"
#include <util/delay.h>
#include "controller.h"
#include "config.h"
#include "lcd.h"

extern volatile System oasis3;

/*
 * buffer mirrors the characters displayed on the LCD
 */
static char _lcd_buffer[LCDRows][LCDCols] = {{CharSpace}};

/*
 * changed lets us know which characters to
 * update when the buffer is printed again.
 */
static uint8_t _lcd_changed = 0;
static uint16_t _lcd_changes[LCDRows][LCDCols] = {{ChangedChar}};
static uint8_t _lcd_icons[8][8] = {{0}};
static uint8_t _lcd_send_data;
static uint8_t _lcd_delay = 0;
static uint8_t _lcd_custom_icons[_CustomChars_][8] = {
	[CustomCharTimeout] = {
		0x0E,	// ....###.
		0x04,	// .....#..
		0x04,	// .....#..
		0x04,	// .....#..
		0x04,	// .....#..
		0x04,	// .....#..
		0x04,	// .....#..
		0x04	// .....#..
	},
	[CustomCharDigitBlock1] = {
		0x1F,	// ...#####
		0x1F,	// ...#####
		0x00,	// ........
		0x00,	// ........
		0x00,	// ........
		0x00,	// ........
		0x00,	// ........
		0x00	// ........
	},
	[CustomCharDigitBlock2] = {
		0x00,	// ........
		0x00,	// ........
		0x00,	// ........
		0x00,	// ........
		0x00,	// ........
		0x00,	// ........
		0x1F,	// ...#####
		0x1F	// ...#####
	},
	[CustomCharDigitBlock3] = {
		0x1F,	// ...#####
		0x1F,	// ...#####
		0x00,	// ........
		0x00,	// ........
		0x00,	// ........
		0x00,	// ........
		0x1F,	// ...#####
		0x1F	// ...#####
	},
	[CustomCharHome] = {
		0x00,	// ........
		0x04,	// .....#..
		0x0E,	// ....###.
		0x1F,	// ...#####
		0x1F,	// ...#####
		0x1B,	// ...##.##
		0x1B, 	// ...##.##
		0x00	// ........
	},
	[CustomCharLocked] = {
		0x0E,	// ....###.
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x1F,	// ...#####
		0x1B,	// ...##.##
		0x1B,	// ...##.##
		0x1F, 	// ...#####
		0x00	// ........
	},
	[CustomCharPump] = {
		0x02,	// ......#.
		0x06,	// .....##.
		0x0A,	// ....#.#.
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x0E,	// ....###.
		0x00	// ........
	},
	[CustomCharUVLampOff] = {
		0x0E,	// ....###.
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x0A,	// ....#.#.
		0x0E, 	// ....###.
		0x00	// ........
	},
	[CustomCharUVLampOn] = {
		0x0E,	// ....###.
		0x11,	// ...#...#
		0x15,	// ...#.#.#
		0x15,	// ...#.#.#
		0x15,	// ...#.#.#
		0x0A,	// ....#.#.
		0x0E, 	// ....###.
		0x00	// ........
	},
	[CustomCharBattery0] = {
		0x18,	// ....###.
		0x08,	// ...##.##
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x11,	// ...#####
		0x00	// ........
	},
	[CustomCharBattery20] = {
		0x18,	// ....###.
		0x08,	// ...##.##
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x11,	// ...#####
		0x11,	// ...#####
		0x00	// ........
	},
	[CustomCharBattery40] = {
		0x18,	// ....###.
		0x08,	// ...##.##
		0x11,	// ...#...#
		0x11,	// ...#...#
		0x11,	// ...#####
		0x11,	// ...#####
		0x11,	// ...#####
		0x00	// ........
	},
	[CustomCharBattery60] = {
		0x18,	// ....###.
		0x08,	// ...##.##
		0x11,	// ...#...#
		0x11,	// ...#####
		0x11,	// ...#####
		0x11,	// ...#####
		0x11,	// ...#####
		0x00	// ........
	},
	[CustomCharBattery80] = {
		0x18,	// ....###.
		0x08,	// ...##.##
		0x11,	// ...#####
		0x11,	// ...#####
		0x11,	// ...#####
		0x11,	// ...#####
		0x11,	// ...#####
		0x00	// ........
	},
	[CustomCharBattery100] = {
		0x18,	// ....###.
		0x08,	// ...#####
		0x11,	// ...#####
		0x11,	// ...#####
		0x11,	// ...#####
		0x11,	// ...#####
		0x11,	// ...#####
		0x00	// ........
	},
	[CustomCharBatteryCharging] = {
		0x18,	// ....###.
		0x08,	// ...#####
		0x11,	// ...##.##
		0x11,	// ...#...#
		0x11,	// ...##.##
		0x11,	// ...#####
		0x11,	// ...#####
		0x00	// ........
	},
	[CustomCharBatteryNone] = {
		0x0E,	// ....###.
		0x1F,	// ...#####
		0x1E,	// ...####.
		0x1C,	// ...###..
		0x19,	// ...##..#
		0x13,	// ...#..##
		0x07,	// .....###
		0x00	// ........
	},
	[CustomCharSolar] = {
		0x1F,	// ...#####
		0x15,	// ...#.#.#
		0x1F,	// ...#####
		0x15,	// ...#.#.#
		0x1F,	// ...#####
		0x15,	// ...#.#.#
		0x1F,	// ...#####
		0x00,	// ........
	},
	[CustomCharAC] = {
		0x1F,	// ....#...
		0x15,	// ...#.#..
		0x1F,	// ...###..
		0x15,	// ...#.#..
		0x1F,	// ......##
		0x15,	// .....#..
		0x1F,	// ......##
		0x00,	// ........
	},
	[CustomCharDC] = {
		0x1F,	// ...##...
		0x15,	// ...#.#..
		0x1F,	// ...#.#..
		0x15,	// ...##...
		0x1F,	// ......##
		0x15,	// .....#..
		0x1F,	// ......##
		0x00,	// ........
	}
};

const char *big_chars[16][2] = {
//	{ top row chars,	bottom row chars }
	{ "\x2B",			CharSpace },		// (43) plus
	{ CharSpace,		"\x2C" },			// (44) comma
	{ "\x2D",			CharSpace },		// (45) minus
	{ CharSpace,		"\x2E" },			// (46) period
	{ "\x2F",			CharSpace },		// (47) slash (kept here to keep sequence)
	{ "\xFF\x04\xFF",	"\xFF\x05\xFF" },	// (48) 0
	{ "\x04\xFF\x20",	"\x05\xFF\x05" },	// (49) 1
	{ "\x06\x06\xFF",	"\xFF\x05\x05" },	// (50) 2
	{ "\x04\x06\xFF",	"\x05\x05\xFF" },	// (51) 3
	{ "\xFF\x05\xFF",	"\x20\x20\xFF" },	// (52) 4
	{ "\xFF\x06\x06",	"\x05\x05\xFF" },	// (53) 5
	{ "\xFF\x06\x06",	"\xFF\x05\xFF" },	// (54) 6
	{ "\x04\x04\xFF",	"\x20\x20\xFF" },	// (55) 7
	{ "\xFF\x06\xFF",	"\xFF\x05\xFF" },	// (56) 8
	{ "\xFF\x06\xFF",	"\x05\x05\xFF" },	// (57) 9
	{ "\xA5",			"\xA5" }			// (58) colon

//	all other characters will be displayed as:
//	{ "\x##",			CharSpace }
};

void lcd_buffer_flush (void)
{
	if (!_lcd_changed) return;

	uint8_t byte;
	uint8_t change;

	for (uint8_t y = 0; y < LCDRows; y++)
	{
		for (uint8_t x = 0; x < LCDCols; x++)
		{
			change = _lcd_changes[y][x];

			if (change != UnchangedChar)
			{
				// clear the 'change' flags
				_lcd_changed = 0;
				_lcd_changes[y][x] = UnchangedChar;
				byte = _lcd_buffer[y][x];

				// update status icons (timeout, pump, lamp, power)
				if (byte <= 3 && change == ChangedIcon)
				{
//debug_on(0);
					_lcd_custom(byte, _lcd_icons[byte]);
//debug_off(0);
				}

				lcd_goto(x, y);
				lcd_data(byte);
			}
		}
	}
}

/*
 * write a string to the buffer
 */
void lcd_buffer_print (uint8_t flags, char *str)
{
	static uint8_t areas[_LCDAreas_][4] = {
		[LCDAreaLine0] = { 0, 0, 19, 0 },
		[LCDAreaLine1] = { 0, 1, 19, 1 },
		[LCDAreaLine2] = { 0, 2, 19, 2 },
		[LCDAreaLine3] = { 0, 3, 19, 3 },
		[LCDAreaTitle] = { 0, 0, 14, 0 },
		[LCDAreaIconLocked] = { 15, 0, 15, 0 },
		[LCDAreaIconsFilter] = { 16, 0, 17, 0 },
		[LCDAreaIconPower] = { 18, 0, 18, 0 },
		[LCDAreaIconMessages] = { 19, 0, 19, 0 },
		[LCDAreaData] = {0, 1, 19, 2 },
		[LCDAreaData0] = { 0, 1, 10, 1 },
		[LCDAreaData1] = { 11, 1, 19, 1 },
		[LCDAreaData2] = { 0, 2, 10, 2 },
		[LCDAreaData3] = { 11, 2, 19, 2 },
		[LCDAreaMenu0] = { 0, 3, 9, 3 },
		[LCDAreaMenu1] = { 11, 3, 19, 3 },
		[LCDAreaTimeout] = { 10, 3, 10, 3 }
	};

	// flags:
	// bit 8: force change
	// bits 7-6: alignment
	// bits 5-0: area

	uint8_t area = flags & 0x1F;
	uint8_t force = flags & PrintForceChange;
	uint8_t align = flags & PrintMaskAlign;
	uint8_t token;
	uint8_t digit = 0;
	uint8_t size = areas[area][2] - areas[area][0] + 1;
	char buf1[LCDCols + 1] = "";
	char buf2[LCDCols + 1] = "";

	if (area == LCDAreaData)
	{
		while (*str)
		{
			switch (*str) {
				case '0': case '1': case '2': case '3': case '4':
				case '5': case '6': case '7': case '8': case '9':
					// follow a preceding digit with a narrow space
					if (digit)
					{
						strcat(buf1, CharSpace);
						strcat(buf2, CharSpace);
					}

					strcat(buf1, big_chars[*str - 43][0]);
					strcat(buf2, big_chars[*str - 43][1]);
					digit = 1;
					break;

				case '+': case ',': case '-': case '.': case '/': case ':':
					strcat(buf1, big_chars[*str - 43][0]);
					strcat(buf2, big_chars[*str - 43][1]);
					digit = 0;
					break;

				case ' ':
					if (!digit)
					{
						strcat(buf1, "\x20\x20\x20\x20");
						strcat(buf2, "\x20\x20\x20\x20");
					}
					else
					{
						strcat(buf1, CharSpace);
						strcat(buf2, CharSpace);
					}
					break;

				default:
					strncat(buf1, str, 1);
					strcat(buf2, CharSpace);
					digit = 0;
					break;
			}

			++str;
		}

		lcd_buffer_print(LCDAreaLine1 | align | force, buf1);
		lcd_buffer_print(LCDAreaLine2 | align | force, buf2);
	}
	else
	{
		token = 0;

		while (*str)
		{
			if (*str == TokenString)
			{
				token = 1;
			}
			else if (token)
			{
				token = 0;
				strcat(buf1, get_config_token(str));
			}
			else
			{
				strncat(buf1, str, 1);
			}

			++str;
		}

		switch (align)
		{
			// pad spaces on the left
			case PrintAlignCenter:
			case PrintAlignRight:
			while (strlen(buf2) < (size - strlen(buf1)) / (align >> 4)/*divide by 2(center) or 1(right)*/)
			{
				strcat(buf2, CharSpace);
			}
			strcat(buf2, buf1);
			strcpy(buf1, buf2);

			// pad spaces on the right
			case PrintAlignLeft:
			while (strlen(buf1) < size)
			{
				strcat(buf1, CharSpace);
			}
			break;
		}

		_lcd_buffer_chars(areas[area][0], areas[area][1], buf1, force);
	}
}

void init_lcd (void)
{
	// wake up
	_delay_ms(110);

	_lcd_cmd(0x03);
	_delay_ms(5);

	_lcd_cmd(0x03);
	_delay_us(110);

	_lcd_cmd(0x03);
	_delay_us(110);

	// set 4-bit mode
	_lcd_cmd(0x02);
	_delay_us(110);

	// 4-bits; 2 logical lines; 5x7 font size
	_lcd_cmd(0x28);
	_delay_us(60);

	// display off
	_lcd_cmd(0x08);
	_delay_us(60);

	// clear display
	_lcd_cmd(0x01);
	_delay_ms(4);

	// entry mode
	_lcd_cmd(0x06);
	_delay_us(60);

	// display on
	_lcd_cmd(0x0C);
	_delay_us(60);

	// custom characters
	_lcd_custom(IconLocked, _lcd_custom_icons[CustomCharLocked]);
	_lcd_custom(IconDigit1, _lcd_custom_icons[CustomCharDigitBlock1]);
	_lcd_custom(IconDigit2, _lcd_custom_icons[CustomCharDigitBlock2]);
	_lcd_custom(IconDigit3, _lcd_custom_icons[CustomCharDigitBlock3]);

	// user 8-bit Timer0 to control LCD brightness:
	// non-inverting mode; phase correct PWM
	TCCR0A = bv(COM0B1) | bv(WGM00);

	// no prescaler
	TCCR0B = bv(CS00);

	// set brightness
	lcd_set_brightness(EventNone, 0);
}

void lcd_buffer_printf (uint8_t flags, const char *format, ...)
{
	char str[LCDCols + 1] = "";

	va_list args;
	va_start(args, format);
	vsnprintf(str, sizeof(str), format, args);
	va_end(args);

	lcd_buffer_print(flags, str);
}

/*
 * write directly into the buffer
 */
inline void lcd_buffer_write (uint8_t x, uint8_t y, uint8_t ch)
{
	_lcd_buffer[y][x] = ch;
	
	//@todo -- figure out if byte structure of custom icon has actually changed
	_lcd_changes[y][x] = ch > 7 ? ChangedChar : ChangedIcon;

	_lcd_changed = 1;
}

void lcd_clear (void)
{
	_lcd_cmd(0x01);
}

inline void _lcd_custom (uint8_t num, uint8_t data[])
{
	_lcd_cmd(0x40 + num * 8);

	for (uint8_t i = 0; i < 8; i++)
	{
		lcd_data(data[i] ^ 0xE0);
	}

	_lcd_cmd(0x80);
}

inline void lcd_data (uint8_t byte)
{
	_lcd_send_data = 1;
	_lcd_send(byte);
}

/*
 * set/get delay
 * delay is set with a non-zero value
 * and reset with zero.
 */
uint8_t lcd_delay (uint8_t delay)
{
	if (_lcd_delay == 0 || delay == 0)
	{
		_lcd_delay = delay;
	}

	return _lcd_delay;
}

void lcd_enable (uint8_t enable)
{
	writeBit(DDR_LCD, PIN_LCD_BRIGHT, enable);
}

inline void lcd_goto (uint8_t x, uint8_t y)
{
	static uint8_t addr[4] = { 0x00, 0x40, 0x14, 0x54 };

	_lcd_cmd(0x80 | (addr[y] + x));
}

uint8_t lcd_make_icon (CustomChar custom, uint8_t icon, IconEffect effect, uint8_t shift)
{
	int8_t delta;
	uint8_t byte = 0;
	uint8_t changed = 0;

	// easy ones first -- just copy the complete icon as is
	if ((effect == IconEffectNone) || (effect == IconEffectFlash && shift))
	{
		memcpy(_lcd_icons[icon], _lcd_custom_icons[custom], 8);
		return 1;
	}

	for (uint8_t i = 0; i < 8; i++)
	{
		switch (effect)
		{
			case IconEffectRotate:
			delta = i - shift;
			byte = _lcd_custom_icons[custom][delta + (delta < 0 ? 8 : 0)];
			break;

			case IconEffectSlide:
			delta = i - shift;
			if (delta >= 0)
			{
				byte = _lcd_custom_icons[custom][delta];
			}
			break;

			case IconEffectInvert:
			byte = ~_lcd_custom_icons[custom][i];
			break;

			case IconEffectMask:
			byte = _lcd_custom_icons[custom][i] ^ _lcd_custom_icons[shift][i];
			break;

			default:
			break;
		}

		if (_lcd_icons[icon][i] != byte)
		{
			changed = 1;
			_lcd_icons[icon][i] = byte;
		}
	}

	return changed;
}

void lcd_refresh (void)
{
	_lcd_changed = 1;
}

uint16_t lcd_set_brightness (__attribute__((unused)) uint16_t event, uint16_t level)
{
	level = level == 0 ? get_config_value(ConfigPanelBrightness) : level;

	OCR0B = max(0x0A, level);

	return 0;
}

uint8_t lcd_undelay (void)
{
	if (_lcd_delay > 0)
	{
		--_lcd_delay;
	}

	return _lcd_delay;
}

uint8_t _lcd_buffer_chars (uint8_t x, uint8_t y, char *str, uint8_t force_change)
{
	uint8_t num = 0;

	while (*str)
	{
		if (force_change || _lcd_buffer[y][x] != *str)
		{
			lcd_buffer_write(x, y, *str);
		}

		++x;
		++str;
		++num;
	}

	return num;
}

inline void _lcd_cmd (uint8_t byte)
{
	_lcd_send_data = 0;
	_lcd_send(byte);
}

inline void _lcd_latch (void)
{
	if (oasis3.computed[SystemInfoReady])
	{
		while (lcd_delay(2)); // 100us
		setBit(PORT_LCD, PIN_LCD_EN);

		while (lcd_delay(1)); // 50us
		clearBit(PORT_LCD, PIN_LCD_EN);
	}
	else
	{
		_delay_us(100);
		setBit(PORT_LCD, PIN_LCD_EN);

		_delay_us(50);
		clearBit(PORT_LCD, PIN_LCD_EN);
	}
}

void _lcd_nibble (uint8_t cmd)
{
	//@todo -- write bits at same time to port?

	// command or data?
	writeBit(PORT_LCD, PIN_LCD_RS, _lcd_send_data); // 0=cmd; 1=data

	// write 4 bit value
	// @todo -- write in parallel?
	writeBit(PORT_LCD, PIN_LCD_D4, cmd & 1);
	writeBit(PORT_LCD, PIN_LCD_D5, cmd & 2);
	writeBit(PORT_LCD, PIN_LCD_D6, cmd & 4);
	writeBit(PORT_LCD, PIN_LCD_D7, cmd & 8);

	_lcd_latch();
/*
	// wait for the lcd to be done
	clearBit(DDR_LCD, PIN_LCD_D7); // input
	clearBit(PORT_LCD, PIN_LCD_RS); // send as command
	setBit(PORT_LCD, PIN_LCD_RW); // read

	do {
		_lcd_latch();
	} while (bitIsSet(DATA_LCD_IN, PIN_LCD_D7));

	clearBit(PORT_LCD, PIN_LCD_RW);
	setBit(DDR_LCD, PIN_LCD_D7); // output
*/
}

inline void _lcd_send (uint8_t byte)
{
	_lcd_nibble(byte >> 4);
	_lcd_nibble(byte & 0x0F);
}
