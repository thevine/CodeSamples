/*
 * Oasis-3 Water Purification System
 *
 * (c) AidGear LLC, 2015
 *
 * Author: Steve Neill <steve@aidgear.com>
 *
 */

#include <stdint.h>
#include "main.h"
#include "board.h"
#include <avr/interrupt.h>
#include "view.h"
#include "controller.h"
#include "filter.h"
#include "info.h"
#include "messages.h"
#include "panel.h"
#include "lcd.h"

static volatile uint32_t msecs = 0; // about 49 days max
static volatile uint32_t next_timer_event = 0;
TimerEvent timers[_TimerTypes_];
extern ConfigGroup config_groups[];

const Timer timer_profiles[_TimerProfiles_] = {
	[TimerProfileNavigation] = {
		.type = TimerNavigation,
		.duration = 8000,
		.cycle = TimerCycleView,
		.priority = PriorityNormal,
		.view = 0
	},
	[TimerProfilePumpStart] = {
		.type = TimerFilter,
		.duration = 1000,
		.cycle = TimerCycleView,
		.priority = PriorityNormal,
		.view = ViewFilter
	},
	[TimerProfileUVStartStop] = {
		.type = TimerFilter,
		.duration = 8000,
		.cycle = TimerCycleFastIcon,
		.priority = PriorityNormal,
		.view = ViewFilter
	},
	[TimerProfileFilterRun] = {
		.type = TimerFilter,
		.duration = TimeoutNone,
		.cycle = TimerCycleSensor,
		.priority = PriorityNormal,
		.view = ViewFilter
	},
	[TimerProfileFilterPause] = {
		.type = TimerFilter,
		.duration = 20000,
		.cycle = TimerCycleView,
		.priority = PriorityNormal,
		.view = ViewFilter
	},
	[TimerProfileCycleView] = {
		.type = TimerView,
		.duration = TimeoutNone,
		.cycle = TimerCycleView,
		.priority = PriorityNormal,
		.view = 0
	}
};

// System-wide events are used if no State or View event of the same type is found
volatile System oasis3 = {
	.flash = 0,
	.computed[SystemInfoReady] = 0,
	.events = {
		// home events
		{
			.type = EventHomeEnter,
			.update = UpdateTimeout,
			.stop_timer = TimerNavigation
		},

		// menu events
		{
			.type = EventMenuEnter,
			.start_timer = TimerProfileNavigation
		},
		{
			.type = EventMenuCycle,
			.update = UpdateTimeout
		},
		{
			.type = EventMenuTimeout,
			.route = NavigationHome
		},

		// item events
		{
			.type = EventItemEnter,
			.start_timer = TimerProfileNavigation
		},
		{
			.type = EventItemCycle,
			.update = UpdateTimeout
		},

		// confirm events
		{
			.type = EventConfirmEnter,
			.start_timer = TimerProfileNavigation
		},
		{
			.type = EventConfirmCycle,
			.update = UpdateTimeout
		},
		{
			.type = EventConfirmTimeout,
			.route = NavigationItem
		},
		{
			.type = EventConfirmRightShort,
			.route = NavigationItem
		}
	}
};

const State states[_StateTypes_] = {
	[StateFilterLocked] = {
		.title = "Filter locked",
		.view = ViewFilter,
		.menus = {
			[MenuHomeLeft] = bv(MenuOptionMenu),
			[MenuHomeRight] = bv(MenuOptionUnlock),
			[MenuMenuLeft] = MenuMain,
			[MenuMenuRight] = bv(MenuOptionOK)
		},
		.events = {
			// state events
			{
				.type = EventStateEnter,
				.process = stop_devices,
				.process_parameter = FilterDevicePump | FilterDeviceUV
			},

			// home events
			{
				.type = EventHomeLeftShort,
				.route = NavigationMenu
			},
			{
				.type = EventHomeRightLong,
				.process = toggle_lock,
				.update = UpdateNavigation
			},

			// menu events
			{
				.type = EventMenuLeftShort,
				.process = next_menu_option,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventMenuLeftLong,
				.route = NavigationHome
			},
			{
				.type = EventMenuRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonLeft
			},
			{
				.type = EventMenuRightLong,
				.process = toggle_lock,
				.update = UpdateNavigation
			}
		}
	},
	[StateFilterReady] = {
		.title = "Filter ready",
		.view = ViewFilter,
		.menus = {
			[MenuHomeLeft] = bv(MenuOptionMenu),
			[MenuHomeRight] = bv(MenuOptionStart),
			[MenuMenuLeft] = MenuMain,
			[MenuMenuRight] = bv(MenuOptionOK)
		},
		.events = {
			// state events
			{
				.type = EventStateEnter,
				.process = stop_devices,
				.process_parameter = FilterDevicePump | FilterDeviceUV
			},

			// home events
			{
				.type = EventHomeLeftShort,
				.route = NavigationMenu,
			},
			{
				.type = EventHomeRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonRight
			},
			{
				.type = EventHomeRightLong,
				.process = toggle_lock,
				.update = UpdateNavigation
			},

			// menu events
			{
				.type = EventMenuLeftShort,
				.process = next_menu_option,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventMenuLeftLong,
				.route = NavigationHome
			},
			{
				.type = EventMenuRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonLeft
			},
			{
				.type = EventMenuRightLong,
				.process = toggle_lock,
				.update = UpdateNavigation
			}
		}
	},
	[StateFilterStartUV] = {
		.title = "Starting...",
		.view = ViewFilter,
		.menus = {
			[MenuHomeRight] = bv(MenuOptionStop)
		},
		.events = {
			// state events
			{
				.type = EventStateEnter,
				.process = start_devices,
				.process_parameter = FilterDeviceUV,
				.start_timer = TimerProfileUVStartStop
			},
			{
				.type = EventStateCycle,
				.process = check_filter,
				.process_parameter = SensorsUV
			},
			{
				.type = EventStateTimeout,
				.process = start_filter
			},

			// home events
			{
				.type = EventHomeRightShort,
				
				// stop immediately (rather than routing to StateFilterStopping)
				.route = StateFilterReady | NavigationHome
			}
		}
	},

	[StateFilterStartPump] = {
		.title = "Starting...",
		.view = ViewFilter,
		.menus = {
			[MenuHomeRight] = bv(MenuOptionStop)
		},
		.events = {
			// state events
			{
				.type = EventStateEnter,
				.process = start_devices,
				.process_parameter = FilterDevicePump,
				.start_timer = TimerProfilePumpStart
			},
			{
				.type = EventStateCycle,
				.process = check_filter,
				.process_parameter = SensorsPump			},
			{
				.type = EventStateTimeout,
				.process = start_filter
			},

			// home events
			{
				.type = EventHomeRightShort,

				// stop immediately (rather than routing to StateFilterStopping)
				.route = StateFilterReady | NavigationHome
			}
		}
	},

	[StateFilterRunning] = {
		.title = "Running...",
		.view = ViewFilter,
		.events = {
			// state events
			{
				.type = EventStateEnter,
				.start_timer = TimerProfileFilterRun,
				.route = FilterOnHome
			},
			{
				.type = EventStateCycle,
				.process = check_filter,
				.process_parameter = SensorsPump | SensorsUV | SensorsFilter
			}
		}
	},

	[StateFilterPaused] = {
		.title = "Resuming...",
		.view = ViewFilter,
		.menus = {
			[MenuHomeLeft] = bv(MenuOptionMenu),
			[MenuHomeRight] = bv(MenuOptionStop),
			[MenuMenuLeft] = MenuMain,
			[MenuMenuRight] = bv(MenuOptionOK)
		},
		.events = {
			// state events
			{
				.type = EventStateEnter,
				.process = stop_devices,
				.process_parameter = FilterDevicePump,
				.start_timer = TimerProfileFilterPause
			},
			{
				.type = EventStateCycle,
				.update = UpdateData
			},
			{
				.type = EventStateTimeout,
				.process = start_filter
			},

			// home events
			{
				.type = EventHomeLeftShort,
				.route = NavigationMenu
			},
			{
				.type = EventHomeRightShort,
				.route = StateFilterReady | NavigationHome
			},

			// menu events
			{
				.type = EventMenuLeftShort,
				.process = next_menu_option,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventMenuLeftLong,
				.route = NavigationHome
			},
			{
				.type = EventMenuRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonLeft
			}
		}
	},
	[StateFilterStopping] = {
		.title = "Stopping...",
		.view = ViewFilter,
		.menus = {
			[MenuHomeRight] = bv(MenuOptionResume),
		},
		.events = {
			// state events
			{
				.type = EventStateEnter,
				.process = stop_devices,
				.process_parameter = FilterDevicePump,
				.start_timer = TimerProfileUVStartStop
			},
			{
				.type = EventStateCycle,
				.update = UpdateData
			},
			{
				.type = EventStateTimeout,
				.route = FilterOffHome
			},

			// home events
			{
				.type = EventHomeRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonRight
			}
		}
	},
	[StateStatus] = {
		.title = "$s",
		.view = ViewStatus,
		.menus = {
			[MenuHomeLeft] = bv(MenuOptionMenu),
			[MenuHomeRight] = bv(MenuOptionStart) | bv(MenuOptionStop),
			[MenuMenuLeft] = MenuMain,
			[MenuMenuRight] = bv(MenuOptionOK),
			[MenuItemLeft] = bv(MenuOptionMenu),
			[MenuItemRight] = bv(MenuOptionIofN)
		},
		.events = {
			// state events
			{
				.type = EventStateEnter,
				.start_timer = TimerProfileCycleView
			},
			{
				.type = EventStateCycle,
				.update = UpdateData
			},

			// home events
			{
				.type = EventHomeLeftShort,
				.route = NavigationMenu
			},
			{
				.type = EventHomeRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonRight
			},

			// menu events
			{
				.type = EventMenuLeftShort,
				.process = next_menu_option,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventMenuLeftLong,
				.route = NavigationHome
			},
			{
				.type = EventMenuRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonLeft
			},

			// item events
			{
				.type = EventItemTimeout,
				.route = NavigationHome
			},
			{
				.type = EventItemLeftShort,
				.route = NavigationMenu
			},
			{
				.type = EventItemLeftLong,
				.route = NavigationHome
			},
			{
				.type = EventItemRightShort,
				.process = next_status_item,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventItemRightLong,
				.process = default_status
			}
		}
	},
	[StateMessages] = {
		.title = "Messages:",
		.view = ViewMessages,
		.menus = {
			[MenuMenuLeft] = MenuMain,
			[MenuMenuRight] = bv(MenuOptionOK),
			[MenuItemLeft] = bv(MenuOptionMenu),
			[MenuItemRight] = bv(MenuOptionIofN),
			[MenuConfirmLeft] = bv(MenuOptionYes),
			[MenuConfirmRight] = bv(MenuOptionNo)
		},
		.events = {
			// menu events
			{
				.type = EventMenuTimeout,
				.route = NavigationItem
			},
			{
				.type = EventMenuLeftShort,
				.process = next_menu_option,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventMenuRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonLeft
			},
			{
				.type = EventMenuLeftLong,
				.process = goto_home
			},
			{
				.type = EventMenuRightLong,
				.process = clear_current_message
			},

			// item events
			{
				.type = EventItemTimeout,
				.process = goto_home
			},
			{
				.type = EventItemLeftShort,
				.route = NavigationMenu
			},
			{
				.type = EventItemLeftLong,
				.process = goto_home
			},
			{
				.type = EventItemRightShort,
				.process = next_message_item,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventItemRightLong,
				.process = goto_message_reset
			},

			// confirm events
			{
				.type = EventConfirmLeftShort,
				.process = clear_current_message
			}
		}
	},
	[StateConfigGroup] = {
		.title = "Config. Menu:",
		.view = ViewConfig,
		.menus = {
			[MenuItemLeft] = bv(MenuOptionFilter) | bv(MenuOptionPanel),
			[MenuItemRight] = bv(MenuOptionOK),
			[MenuConfirmLeft] = bv(MenuOptionYes),
			[MenuConfirmRight] = bv(MenuOptionNo)
		},
		.events = {
			// item events
			{
				.type = EventItemTimeout,
				.process = goto_home
			},
			{
				.type = EventItemLeftShort,
				.process = next_menu_option,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventItemLeftLong,
				.process = goto_home
			},
			{
				.type = EventItemRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonLeft
			},
			{
				.type = EventItemRightLong,
				.route = NavigationConfirm
			},

			// confirm events
			{
				.type = EventConfirmLeftShort,
				.process = default_current_config_group
			}
		}
	},
	[StateConfigOption] = {
		.title = "$C Config:",
		.view = ViewConfig,
		.menus = {
			[MenuItemLeft] = bv(MenuOptionIofN),
			[MenuItemRight] = bv(MenuOptionEdit) | bv(MenuOptionNoEdit),
			[MenuConfirmLeft] = bv(MenuOptionYes),
			[MenuConfirmRight] = bv(MenuOptionNo)
		},
		.events = {
			// item events
			{
				.type = EventItemEnter,
				.start_timer = TimerProfileNavigation,
				.process = fix_config_menu
			},
			{
				.type = EventItemTimeout,
				.route = StateConfigGroup | NavigationItem
			},
			{
				.type = EventItemLeftShort,
				.process = next_config_option,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventItemLeftLong,
				.route = StateConfigGroup | NavigationItem
			},
			{
				.type = EventItemRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonRight
			},
			{
				.type = EventItemRightLong,
				.route = NavigationConfirm
			},

			// confirm events
			{
				.type = EventConfirmLeftShort,
				.process = default_current_config_option
			}
		}
	},
	[StateConfigValue] = {
		.title = "$C Config:",
		.view = ViewConfig,
		.menus = {
			[MenuItemLeft] = bv(MenuOptionIofN),
			[MenuItemRight] = bv(MenuOptionSave)
		},
		.events = {
			// item events
			{
				.type = EventItemTimeout,
				.route = StateConfigOption | NavigationItem,

				// reset the LCD brightness if it was changed but not saved
				.process = lcd_set_brightness,
				.process_parameter = 0
			},
			{
				.type = EventItemLeftShort,
				.process = next_config_value,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventItemLeftLong,
				.route = StateConfigOption | NavigationItem
			},
			{
				.type = EventItemRightShort,
				.process = apply_config,
				.route = StateConfigOption | NavigationItem
			}
		}
	},
	[StateInfoGroup] = {
		.title = "Info Menu:",
		.view = ViewInfo,
		.menus = {
			[MenuItemLeft] = bv(MenuOptionUsage) | bv(MenuOptionSystem) | bv(MenuOptionAbout),
			[MenuItemRight] = bv(MenuOptionOK)
		},
		.events = {
			// item events
			{
				.type = EventItemTimeout,
				.process = goto_home
			},
			{
				.type = EventItemLeftShort,
				.process = next_menu_option,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventItemLeftLong,
				.process = goto_home
			},
			{
				.type = EventItemRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonLeft
			}
		}
	},
	[StateInfoItem] = {
		.title = "$I Info:",
		.view = ViewInfo,
		.menus = {
			[MenuMenuLeft] = MenuMain,
			[MenuMenuRight] = bv(MenuOptionOK),
			[MenuItemLeft] = bv(MenuOptionMenu),
			[MenuItemRight] = bv(MenuOptionIofN),
			[MenuConfirmLeft] = bv(MenuOptionYes),
			[MenuConfirmRight] = bv(MenuOptionNo)
		},
		.events = {
			// menu events
			{
				.type = EventMenuTimeout,
				.route = NavigationItem
			},
			{
				.type = EventMenuLeftShort,
				.process = next_menu_option,
				.start_timer = TimerProfileNavigation
			},
			{
				.type = EventMenuLeftLong,
				.process = goto_home
			},
			{
				.type = EventMenuRightShort,
				.process = select_menu_option,
				.process_parameter = MaskButtonLeft
			},

			// item events
			{
				.type = EventItemTimeout,
				.process = goto_home
			},
			{
				.type = EventItemLeftShort,
				.route = NavigationMenu
			},
			{
				.type = EventItemLeftLong,
				.route = StateInfoGroup | NavigationItem
			},
			{
				.type = EventItemRightShort,
				.process = next_info_item,
				.start_timer = TimerProfileNavigation
			},

			// allow the user to Confirm they want
			// to reset the current info value...
			{
				.type = EventItemRightLong,
				.process = goto_info_reset
			},

			// confirm events
			{
				.type = EventConfirmLeftShort,
				.process = reset_info_item
			}
		}
	}
};

MenuOption menu_option[_MenuOptions_] = {
	[MenuOptionMenu] = {
		.title = "Menu"
	},
	[MenuOptionExit] = {
		.title = "Exit"
	},
	[MenuOptionUnlock] = {
		.title = "Unlock"
	},
	[MenuOptionStart] = {
		.title = "Start",
		.process = start_filter
	},
	[MenuOptionResume] = {
		.title = "Resume",
		.process = start_filter
	},
	[MenuOptionStop] = {
		.title = "Stop",
		.route = StateFilterStopping
	},
	[MenuOptionStatus] = {
		.title = "Status",
		.route = StateStatus | NavigationItem
	},
	[MenuOptionMessages] = {
		.title = "Messages",
		.route = StateMessages | NavigationItem
	},
	[MenuOptionConfigure] = {
		.title = "Configure",
		.route = StateConfigGroup | NavigationItem
	},
	[MenuOptionInfo] = {
		.title = "Info",
		.route = StateInfoGroup | NavigationItem
	},
	[MenuOptionFilter] = {
		.title = "Filter",
		.process = set_config_group,
		.process_parameter = ConfigGroupFilter,
		.route = StateConfigOption | NavigationItem
	},
	[MenuOptionPanel] = {
		.title = "Panel",
		.process = set_config_group,
		.process_parameter = ConfigGroupPanel,
		.route = StateConfigOption | NavigationItem
	},
	[MenuOptionUsage] = {
		.title = "Usage",
		.process = set_info_group,
		.process_parameter = InfoGroupUsage,
		.route = StateInfoItem | NavigationItem
	},
	[MenuOptionSystem] = {
		.title = "System",
		.process = set_info_group,
		.process_parameter = InfoGroupSystem,
		.route = StateInfoItem | NavigationItem
	},
	[MenuOptionAbout] = {
		.title = "About",
		.process = set_info_group,
		.process_parameter = InfoGroupAbout,
		.route = StateInfoItem | NavigationItem
	},
	[MenuOptionOK] = {
		.title = "OK"
	},
	[MenuOptionEdit] = {
		.title = "Edit",
		.route = StateConfigValue | NavigationItem
	},
	[MenuOptionNoEdit] = {
		.title = CharIconLocked,
		.process = invalid_action,
		.route = NavigationItem
	},
	[MenuOptionSave] = {
		.title = "Save"
	},
	[MenuOptionYes] = {
		.title = "Yes"
	},
	[MenuOptionNo] = {
		.title = "No"
	},
	[MenuOptionIofN] = {
		.title = "$a$i$n"
	}
};

const StatusItem status_items[_StatusTypes_] = {
	[StatusFilterSummary] = {
		.title = "Filter Summary",
		.process = view_filter_summary
	},
	[StatusBatterySummary] = {
		.title = "Battery Summary",
		.process = view_battery_summary
	},
	[StatusSolarSummary] = {
		.title = "Solar Summary",
		.process = view_solar_summary
	},
	[StatusPressure] = {
		.title = "Pressure",
		.process = view_filter_pressure
	},
	[StatusFlowRate] = {
		.title = "Flow Rate",
		.process = view_flow_rate
	},
	[StatusUVWavelength] = {
		.title = "UV Wavelength",
		.process = view_uv_wavelength
	},
	[StatusWaterTemp] = {
		.title = "Water Temp.",
		.process = view_water_temperature
	},
	[StatusTDS] = {
		.title = "TDS Level",
		.process = view_tds
	},
	[StatusBatteryVoltage] = {
		.title = "Battery Voltage",
		.process = view_battery_voltage
	},
	[StatusBatteryLoad] = {
		.title = "Battery Load",
		.process = view_battery_load
	},
	[StatusSolarVoltage] = {
		.title = "Solar Voltage",
		.process = view_solar_voltage
	},
	[StatusSolarLoad] = {
		.title = "Solar Load",
		.process = view_solar_load
	},
	[StatusSolarPower] = {
		.title = "Solar Power",
		.process = view_solar_power
	},
	[StatusSystemVoltage] = {
		.title = "System Voltage",
		.process = view_system_voltage
	},
	[StatusSystemLoad] = {
		.title = "System Load",
		.process = view_system_load
	},
	[StatusVolume] = {
		.title = "Volume",
		.process = view_volume
	},
	[StatusTimer] = {
		.title = "Filter Timer",
		.process = view_timer
	}
};

static const uint16_t menu_mask[_MenuTypes_] = {
	NavigationHome | MaskButtonLeft,
	NavigationMenu | MaskButtonLeft,
	NavigationItem | MaskButtonLeft,
	NavigationConfirm | MaskButtonLeft,
	NavigationHome | MaskButtonRight,
	NavigationMenu | MaskButtonRight,
	NavigationItem | MaskButtonRight,
	NavigationConfirm | MaskButtonRight
};

/*
 * Heartbeat timer ISR:
 * - fired every 50us
 */
ISR (TIMER1_COMPA_vect)
{
	static uint8_t processing = WithEvents;
	static uint16_t icon_wait = 0;
	static uint8_t write_wait = EEPROMUpdateSeconds;
	static uint16_t tick = 0;
	static uint8_t per_ms = 20;

	// the system isn't ready yet
	if (oasis3.computed[SystemInfoReady] == 0) return;

	// lcd is busy -- let it finish
	if (lcd_undelay()) return;

	// count up to 1ms (50us x 20 = 1ms)
	if (--per_ms) return;
	per_ms = 20;

	// @todo -- move to timer event?
	if (++icon_wait == TimerCycleBlink)
	{
		if (oasis3.active_display)
		{
			update_icons(IconMaskFilter | IconMaskPower);
		}

		oasis3.flash ^= 1;
		icon_wait = 0;
	}

	switch (++tick)
	{
		case 1:
		if (oasis3.active_display)
		{
			update_icons(IconMaskMessages);
		}

		if (oasis3.messages)
		{
			panel_alerts(oasis3.messages);
		}
		break;

		case 1000:
		if (oasis3.popup_message)
		{
			if (--oasis3.popup_message == 0)
			{
				update_display(UpdateTitle);
			}
		}

		if (oasis3.active_display && get_config(ConfigPanelDisplay) == 0)
		{
			if (--oasis3.active_display == 0)
			{
				// stop display brightness pwm
				lcd_enable(0);
			}
		}

		// track time -- only when filtering
		if (is_filter_state(FilterRunning))
		{
			if (++oasis3.seconds == 60)
			{
				oasis3.seconds = 0;

				if (++oasis3.minutes == 60)
				{
					oasis3.minutes = 0;

					++oasis3.hours;
				}
			};

			if (++write_wait == EEPROMUpdateSeconds)
			{
				write_data();
				write_wait = 0;
			}
		}

		tick = 0;
		break;
	}

	if (oasis3.buttons)
	{
		if (oasis3.active_display == 0)
		{
			// wake-up the display
			lcd_enable(1);
			processing = WithoutEvents;
		}
		else
		{
			process_buttons(processing);
		}

		// keep the display active
		oasis3.active_display = DisplayTimeout;
	}
	else
	{
		processing = WithEvents;
	}

	if (++msecs == next_timer_event)
	{
		update_timers();
	}

	return;
}

void fire_event (uint16_t event_type, ViewType view_type)
{
	uint8_t i;

	if (view_type == ViewCurrent)
	{
		view_type = oasis3.current_view;
	}

	/*
	 * First, search states
	 */
	State state = states[oasis3.view_state[view_type]];

	i = 0;
	while (i < MaxEvents && state.events[i].type)
	{
		if (state.events[i].type == event_type)
		{
			return process_event(state.events[i]);
		}

		i++;
	}

	/*
	 * Finally, search system
	 */
	i = 0;
	while (i < MaxEvents && oasis3.events[i].type)
	{
		if (oasis3.events[i].type == event_type)
		{
			return process_event(oasis3.events[i]);
		}

		i++;
	}
}

MenuOption get_button_menu (MenuType menu)
{
	return menu_option[__builtin_ctzll(oasis3.menu_item[menu])];
}

MenuOption get_current_menu_option (uint16_t mask)
{
	MenuType menu;

	for (menu = 0; menu < _MenuTypes_; menu++)
	{
		if (menu_mask[menu] == mask) break;
	}

	return get_button_menu(menu);
}

MenuOption get_menu_option (MenuOptionType menu)
{
	return menu_option[menu];
}

uint16_t get_menu_mask (MenuType menu)
{
	return menu_mask[menu];
}

uint32_t get_menu_options (MenuType menu)
{
	uint32_t options = states[oasis3.view_state[oasis3.current_view]].menus[menu];
	uint32_t old_options = options;

	// remove Start/Stop options
	if (is_filter_state(FilterLocked))
	{
		clearBit(options, MenuOptionStart);
	}

	clearBit(options, is_filter_state(FilterStartingUV | FilterStartingPump | FilterRunning) ? MenuOptionStart : MenuOptionStop);

	// remove Edit option if config option is not editable
	if (bitIsSet(options, MenuOptionEdit))
	{
		uint8_t editable = (config_groups[oasis3.state_item[StateConfigGroup]].items[oasis3.state_item[StateConfigOption]].editable)(EventNone, 0);
		clearBit(options, editable == 1 ? MenuOptionNoEdit : MenuOptionEdit);
	}

	// reset the current menu option if any options have been removed.
	if (options != old_options)
	{
		oasis3.menu_item[menu] = firstBit(options);
	}

	return options;
}

uint16_t goto_home (__attribute__((unused)) uint16_t event, __attribute__((unused)) uint16_t parameter)
{
	set_route(
		is_filter_state(FilterLocked)
			? (StateFilterLocked | NavigationHome)
			: is_filter_state(FilterRunning)
				? FilterOnHome
				: FilterOffHome
	);

	update_display(UpdateAll);

	return 0;
}

void init_controller (void)
{
	// --------------------
	// system clock
	// --------------------
	OCR1A = 0x031F;	// 50us (fastest LCD update period)

	// normal IO port operation
	TCCR1A = 0x00;

	// CTC mode; no-prescaler
	TCCR1B = bv(WGM12) | bv(CS10);

	// set and enable interrupts
	TIMSK1 = bv(OCIE1A);
	TIFR1 = bv(OCF1A);
	
	// starting view and state
	set_route((oasis3.configs[ConfigFilterLock] ? StateFilterLocked : StateFilterReady) | NavigationHome);
}

uint16_t invalid_action (__attribute__((unused)) uint16_t event, __attribute__((unused)) uint16_t parameter)
{
	panel_beep(BeepInvalid);

	return 0;
}

uint16_t next_menu_option (uint16_t event, __attribute__((unused)) uint16_t parameter)
{
	uint16_t mask = event & (MaskNavigation | MaskButtons);

	// clear any popup message
	popup_message("");

	for (MenuType menu = 0; menu < _MenuTypes_; menu++)
	{
		if (menu_mask[menu] == mask)
		{
			uint32_t option = oasis3.menu_item[menu];
			uint32_t options = get_menu_options(menu);

			if (options > 0)
			{
				do {
					if (!(option = (option << 1ULL)))
					{
						option = firstBit(options);
					}
				} while(!(options & option));

				oasis3.menu_item[menu] = option;
				
				return UpdateNavigation;
			}
			break;
		}
	}

	return 0;
}

/*
 * Determine next event time
 */
void next_timer (void)
{
	next_timer_event = 0;

	for (TimerType timer = 0; timer < _TimerTypes_; timer++)
	{
		if (timers[timer].next_event && (next_timer_event == 0 || timers[timer].next_event < next_timer_event))
		{
			next_timer_event = timers[timer].next_event;
		}
	}
}

void process_event (Event event)
{
	uint8_t update = UpdateNone;

	// only stop the timer if there are pending events on it, otherwise
	// it will fire more "timeout" events, which may cause more "exit"
	// events, which will fire more "timeout" events, etc... Not good!
	if (event.stop_timer && timers[event.stop_timer].next_event)
	{
		stop_timer(event.stop_timer, WithoutEvents);
	}

	/*
	 * The event has a process function.
	 * Note: A process function can tell us how to update the display
	 */
	if (event.process)
	{
		update |= (event.process)(event.type, event.process_parameter);
	}

	/*
	 * Start/stop timers
	 */
	if (event.start_timer)
	{
		Timer timer = timer_profiles[event.start_timer];

		start_timer(timer.type, timer.duration, timer.cycle, timer.priority, timer.view);
	}

	/*
	 * Follow route
	 */
	if (event.route)
	{
		set_route(event.route);
	}

	/*
	 * Update display
	 * Note: icons are updated via system timer
	 */
	if (oasis3.active_display)
	{
		if (event.update)
		{
			update |= event.update;
		}
		else
		{
			switch (event.type & MaskTransitions)
			{
				case TransitionEnter:
				update |= UpdateAll;
				break;

				case TransitionCycle:
				update |= (UpdateData | UpdateTimeout);
				break;

				case TransitionTimeout:
				case TransitionShort:
				case TransitionLong:
				update |= UpdateNavigation;
				break;

				default:
				update |= UpdateAll;
				break;
			}
		}

		update_display(update);
	}
}

uint16_t remaining_timeout (TimerType type)
{
	uint32_t expires = timers[type].expires;

	return expires ? expires - msecs : 0;
}

uint16_t select_menu_option (uint16_t event, uint16_t button_mask)
{
	// find the menu this button activates
	MenuOption menu_option = get_current_menu_option((event & MaskNavigation) | button_mask);

	stop_timer(TimerNavigation, 0);

	// clear any popup message
	popup_message("");

	if (menu_option.process)
	{
		(menu_option.process)(event, menu_option.process_parameter);
	}

	if (menu_option.route)
	{
		set_route(menu_option.route);
	}

	return 0;
}

/*
 * A route usually follows a path that fires 4 events:
 * (A) Exit the current navigation context
 * (B) Exit the current state
 * (C) Enter a new state
 * (D) Enter a new navigation context
 */

void set_route (uint16_t route)
{
	StateType state = route & MaskStates;
	ViewType view = states[state].view;
	uint16_t navigation = route & MaskNavigation;

	// current navigation will always have an exit event
	fire_event(oasis3.navigation | TransitionExit, ViewCurrent); // (A)

	// new navigation route
	if (navigation)
	{
		oasis3.navigation = navigation;
	}

	if (state != StateNone)
	{
		// the view isn't changing, so we can safely exit the state
		if (view == oasis3.current_view)
		{
			fire_event(EventStateExit, ViewCurrent); // (B)
		}

		// previous state
		oasis3.recent_state = oasis3.view_state[oasis3.current_view];

		// set the current view
		oasis3.current_view = view;

		// set the current view's state
		oasis3.view_state[view] = state;

		// perform the state event
		fire_event(EventStateEnter, ViewCurrent); // (C)

		// update the menus after entering a state
		reset_menu_options();
	}

	if (navigation)
	{
		fire_event(oasis3.navigation | TransitionEnter, ViewCurrent); // (D)
	}
}

/*
 * Turn event on for the designated period of time
 * (usually no more than 1 heartbeat period)
 */
void start_timer (TimerType type, uint16_t duration, uint16_t cycle, PriorityType priority, ViewType view)
{
	// ignore if there's a higher priority
	if (timers[type].priority > priority)
	{
		return;
	}

	uint32_t now = msecs;

	// timers run within the context of a view, not necessarily the current view
	timers[type].view = view;

	// when the timer expires. A value of 0 means it won't expire
	timers[type].expires = duration == TimeoutNone ? TimeoutNone : now + duration;

	// if cycle > 0, the timer will toggle every cycle
	timers[type].cycle = min(cycle ? cycle : duration, duration == TimeoutNone ? cycle : duration);

	// next timer event
	timers[type].next_event = now + timers[type].cycle;

	// set the priority
	timers[type].priority = priority;

	// enable a hardware event
	if (timers[type].pin)
	{
		setBit(PORT_DIG_OUT, timers[type].pin);
	}

	// set next timer event
	next_timer();
}

void stop_timer (TimerType type, uint8_t allow_events)
{
	//ViewType view =  timers[type].view;

	// disable the timer
	timers[type].cycle = 0;
	timers[type].next_event = 0;
	timers[type].expires = 0;
	timers[type].priority = PriorityNormal;

	// cancel a hardware timer
	if (timers[type].pin)
	{
		clearBit(PORT_DIG_OUT, timers[type].pin);
	}

	if (allow_events == WithEvents)
	{
		switch (type)
		{
			case TimerNavigation:
			fire_event(oasis3.navigation | TransitionTimeout, ViewCurrent);
			break;

			case TimerFilter:
			fire_event(TransitionTimeout, ViewFilter);
			break;

			case TimerView:
			fire_event(TransitionTimeout, ViewCurrent);
			break;

			default:
			break;
		}
	}

	next_timer();
}

// reset menu options for the current view/state
void reset_menu_options (void)
{
	for (MenuType menu = 0; menu < _MenuTypes_; menu++)
	{
		// get the first item
		oasis3.menu_item[menu] = firstBit(get_menu_options(menu));
	}
}

/*
 * Update events
 */
void update_timers (void)
{
	ViewType view;

	for (TimerType type = 0; type < _TimerTypes_; type++)
	{
		if (msecs == timers[type].next_event)
		{
			// expire timers
			if (msecs == timers[type].expires && timers[type].expires != TimeoutNone)
			{
				stop_timer(type, WithEvents);
			}

			// cycle the timer
			else
			{
				// next cycle time
				timers[type].next_event += timers[type].cycle;

				// toggle a hardware event
				if (timers[type].pin)
				{
					toggleBit(PORT_DIG_OUT, timers[type].pin);
				}

				view = timers[type].view;

				// state event cycle process
				switch (type)
				{
					case TimerNavigation:
					fire_event(oasis3.navigation | TransitionCycle, view);
					break;

					case TimerFilter:
					case TimerView:
					fire_event(TransitionCycle, view);
					break;

					default:
					break;
				}
			}
		}
	}

	next_timer();
}
