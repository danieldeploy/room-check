/**
 * Code Generator with daily room state — Apps Script Web App
 *
 * Daily rule:
 * - At the first access on a new date, Previous code is set equal to Actual code.
 * - Therefore, until a room is changed on that date, both values are identical.
 * - Every successful assignment saves the immediately preceding Actual code
 *   as Previous code, saves the new Actual code, and appends a history record.
 */

const CONFIG = Object.freeze({
  VISIBLE_SHEET: 'Interface',
  CODES_SHEET: 'Codes',
  ROOMS_SHEET: 'Rooms',
  HISTORY_SHEET: 'History',
  INDEX_KEY: 'nextCodeIndex',
  SPREADSHEET_ID_KEY: 'spreadsheetId',
  DAILY_NORMALIZED_KEY: 'dailyNormalizedDate',
  CODES_CACHE_KEY: 'roomGeneratorCodesV1',
  ROOMS_CACHE_KEY: 'roomGeneratorRoomsV1',
  USED_CODES_SHEET: 'UsedCodes',
  AUTO_USED_FLAGS_KEY: 'autoUsedCodeFlagsV2',
  USAGE_MIGRATED_KEY: 'usedCodeHistoryMigrated30dV1',
  USED_WINDOW_DAYS: 30
});

const DEFAULT_ROOMS = Object.freeze([
  {id: 'room1', name: 'Room 1'},
  {id: 'room2', name: 'Room 2'},
  {id: 'room3', name: 'Room 3'},
  {id: 'room4', name: 'Room 4'},
  {id: 'room5', name: 'Room 5'},
  {id: 'room6', name: 'Room 6'}
]);

function doGet() {
  return HtmlService
    .createHtmlOutputFromFile('Index')
    .setTitle('Code generator')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

/**
 * Run once from Extensions → Apps Script while the spreadsheet is open.
 * Re-running setup preserves room codes and history.
 */
function setup() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();

  if (!ss) {
    throw new Error(
      'Open this project from the spreadsheet using Extensions → Apps Script, then run setup() again.'
    );
  }

  const interfaceSheet = ss.getSheetByName(CONFIG.VISIBLE_SHEET);
  const codesSheet = ss.getSheetByName(CONFIG.CODES_SHEET);

  if (!interfaceSheet || !codesSheet) {
    throw new Error('The "Interface" and "Codes" sheets are required.');
  }

  const roomsSheet = ensureRoomsSheet_(ss);
  const historySheet = ensureHistorySheet_(ss);
  const usedCodesSheet = ensureUsedCodesSheet_(ss);

  const scriptProperties = PropertiesService.getScriptProperties();
  scriptProperties.setProperty(CONFIG.SPREADSHEET_ID_KEY, ss.getId());

  if (scriptProperties.getProperty(CONFIG.INDEX_KEY) === null) {
    let existingIndex = '0';

    try {
      existingIndex =
        PropertiesService.getDocumentProperties()
          .getProperty(CONFIG.INDEX_KEY) || '0';
    } catch (error) {
      existingIndex = '0';
    }

    scriptProperties.setProperty(CONFIG.INDEX_KEY, existingIndex);
  }

  interfaceSheet.clear();
  interfaceSheet.setHiddenGridlines(true);
  interfaceSheet.setFrozenRows(0);
  interfaceSheet.setFrozenColumns(0);
  interfaceSheet.showSheet();

  codesSheet.getRange('A:A').setNumberFormat('0000');
  roomsSheet.getRange('C:F').setNumberFormat('@');
  usedCodesSheet.getRange('A:A').setNumberFormat('@');
  usedCodesSheet.getRange('B:B').setNumberFormat('yyyy-mm-dd hh:mm:ss');

  scriptProperties.deleteProperty(CONFIG.DAILY_NORMALIZED_KEY);
  normalizeRoomsForToday_(roomsSheet, historySheet);

  const scriptCache = CacheService.getScriptCache();
  scriptCache.remove(CONFIG.CODES_CACHE_KEY);
  scriptCache.remove(CONFIG.ROOMS_CACHE_KEY);

  // One-time migration from the assignment History and current room state.
  if (
    scriptProperties.getProperty(CONFIG.USAGE_MIGRATED_KEY) !== '1'
  ) {
    syncUsedCodesFromHistory_(
      usedCodesSheet,
      historySheet,
      roomsSheet
    );

    scriptProperties.setProperty(
      CONFIG.USAGE_MIGRATED_KEY,
      '1'
    );
  }

  const codes = getCodeList_(codesSheet);

  // The 30-day registry is used only to validate manual input.
  // Auto uses its own normal current-cycle used/unused list.
  initializeAutoUsageState_(
    codes,
    roomsSheet,
    scriptProperties
  );

  codesSheet.hideSheet();
  roomsSheet.hideSheet();
  historySheet.hideSheet();
  usedCodesSheet.hideSheet();

  protectSheet_(interfaceSheet, 'Locked blank interface');
  protectSheet_(codesSheet, 'Locked hidden code list');
  protectSheet_(roomsSheet, 'Locked room state');
  protectSheet_(historySheet, 'Locked assignment history');
  protectSheet_(usedCodesSheet, 'Locked 30-day code usage');

  SpreadsheetApp.flush();

  return {
    ok: true,
    spreadsheetId: ss.getId(),
    totalCodes: codesSheet.getLastRow(),
    totalRooms: DEFAULT_ROOMS.length,
    reuseWindowDays: CONFIG.USED_WINDOW_DAYS,
    message:
      'Setup complete. The 30-day rule applies only to manual input.'
  };
}

/**
 * Loads persistent room state.
 * On the first request of a new date, Previous code is physically saved
 * as equal to Actual code for every room.
 */
function getInitialState() {
  const lock = LockService.getScriptLock();
  lock.waitLock(10000);

  try {
    const data = getGeneratorData_();

    normalizeRoomsForToday_(data.roomsSheet, data.historySheet);

    const states = getRoomStatesCached_(data.roomsSheet);
    const nextUsage = resolveNextUnusedCode_(data, states);
    const today = getTodayKey_();

    return {
      rooms: serializeRooms_(states),
      next: buildPreview_(data.codes, nextUsage.index),
      todayKey: today,
      todayDisplay: formatDisplayDate_(today)
    };
  } finally {
    lock.releaseLock();
  }
}

/**
 * AUTO assignment:
 * - consumes the next sequence code;
 * - copies it on the client;
 * - advances the shared sequence.
 */
function assignPreparedCode(roomId, expectedIndex) {
  const lock = LockService.getScriptLock();
  lock.waitLock(10000);

  try {
    const data = getGeneratorData_();

    normalizeRoomsForToday_(data.roomsSheet, data.historySheet);

    const states = getRoomStatesCached_(data.roomsSheet);
    const usage = resolveNextUnusedCode_(data, states);
    const today = getTodayKey_();

    if (Number(expectedIndex) !== usage.index) {
      return {
        ok: false,
        conflict: true,
        next: buildPreview_(data.codes, usage.index),
        todayKey: today,
        todayDisplay: formatDisplayDate_(today)
      };
    }

    const room = findRoomState_(states, roomId);

    if (!room) {
      throw new Error('The selected room could not be found.');
    }

    const assignedCode = data.codes[usage.index];

    applyRoomCode_(
      data.roomsSheet,
      data.historySheet,
      room,
      assignedCode,
      {
        eventType: 'AUTO_ASSIGNMENT',
        sequencePosition: usage.index + 1,
        sequenceTotal: data.total,
        today: today
      }
    );

    const usedAt = new Date();

    markCodeUsed_(
      data.usedCodesSheet,
      assignedCode,
      usedAt
    );

    data.usedFlags[usage.index] = '1';
    saveAutoUsedFlags_(data.properties, data.usedFlags);

    data.properties.setProperty(
      CONFIG.INDEX_KEY,
      String((usage.index + 1) % data.total)
    );

    cacheRoomStates_(states);

    const nextUsage = resolveNextUnusedCode_(data, states);

    return {
      ok: true,
      mode: 'AUTO',
      assignment: serializeRoom_(room),
      position: usage.index + 1,
      total: data.total,
      next: buildPreview_(data.codes, nextUsage.index),
      todayKey: today,
      todayDisplay: formatDisplayDate_(today)
    };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Manual codes are validated against:
 * - every room's Previous code;
 * - every room's Actual code;
 * - the registry of codes used during the previous 30 days. This check applies only to manual input.
 *
 * When the manual code exists in the master Codes list and is still unused,
 * it is removed from Auto's unused list for the current Auto cycle.
 */
function assignManualCode(roomId, manualCode) {
  const lock = LockService.getScriptLock();
  lock.waitLock(10000);

  try {
    const codeText = String(manualCode || '').trim();

    if (!/^\d{4}$/.test(codeText)) {
      throw new Error('Enter exactly 4 digits.');
    }

    const data = getGeneratorData_();

    normalizeRoomsForToday_(data.roomsSheet, data.historySheet);

    const states = getRoomStatesCached_(data.roomsSheet);
    const room = findRoomState_(states, roomId);
    const today = getTodayKey_();

    if (!room) {
      throw new Error('The selected room could not be found.');
    }

    const masterIndex = data.codeIndex[codeText];
    const lastUsedAt = getCodeLastUsedAt_(
      data.usedCodesSheet,
      codeText
    );
    const usedWithin30Days = isWithinUsageWindow_(lastUsedAt);

    if (usedWithin30Days) {
      const currentUsage = resolveNextUnusedCode_(data, states);

      return {
        ok: false,
        duplicate: true,
        message: 'The code already used!',
        duplicateField: 'Used during the previous 30 days',
        lastUsedAt: serializeDateTime_(lastUsedAt),
        next: buildPreview_(data.codes, currentUsage.index),
        todayKey: today,
        todayDisplay: formatDisplayDate_(today)
      };
    }

    applyRoomCode_(
      data.roomsSheet,
      data.historySheet,
      room,
      codeText,
      {
        eventType: 'MANUAL_ASSIGNMENT',
        sequencePosition: '',
        sequenceTotal: '',
        today: today
      }
    );

    const usedAt = new Date();

    markCodeUsed_(
      data.usedCodesSheet,
      codeText,
      usedAt
    );

    let removedFromUnusedList = false;

    if (masterIndex !== undefined) {
      removedFromUnusedList =
        data.usedFlags[masterIndex] !== '1';

      data.usedFlags[masterIndex] = '1';
      saveAutoUsedFlags_(data.properties, data.usedFlags);
    }

    cacheRoomStates_(states);

    const nextUsage = resolveNextUnusedCode_(data, states);

    return {
      ok: true,
      mode: 'MANUAL',
      assignment: serializeRoom_(room),
      removedFromUnusedList: removedFromUnusedList,
      next: buildPreview_(data.codes, nextUsage.index),
      todayKey: today,
      todayDisplay: formatDisplayDate_(today)
    };
  } finally {
    lock.releaseLock();
  }
}

function applyRoomCode_(
  roomsSheet,
  historySheet,
  room,
  assignedCode,
  options
) {
  const today = options.today || getTodayKey_();
  const now = new Date();
  const previousCode = room.actualCode;
  const previousCodeDate = room.actualCodeDate;
  const actualCode = String(assignedCode).padStart(4, '0');
  const actualCodeDate = today;
  const todayChangedCount = room.todayChangedCount + 1;
  const changed = actualCode !== previousCode;

  room.previousCode = previousCode;
  room.previousCodeDate = previousCodeDate;
  room.actualCode = actualCode;
  room.actualCodeDate = actualCodeDate;
  room.stateDate = today;
  room.todayChangedCount = todayChangedCount;
  room.updatedAt = now;

  roomsSheet
    .getRange(room.rowNumber, 3, 1, 7)
    .setValues([[
      previousCode,
      previousCodeDate,
      actualCode,
      actualCodeDate,
      today,
      todayChangedCount,
      now
    ]]);

  appendHistory_(historySheet, {
    timestamp: now,
    roomId: room.id,
    roomName: room.name,
    previousCode: previousCode,
    previousCodeDate: previousCodeDate,
    actualCode: actualCode,
    actualCodeDate: actualCodeDate,
    changed: changed,
    todayChangedCount: todayChangedCount,
    sequencePosition: options.sequencePosition,
    sequenceTotal: options.sequenceTotal,
    eventType: options.eventType,
    stateDate: today
  });
}

function findRoomState_(states, roomId) {
  return states.find(function(room) {
    return room.id === String(roomId);
  }) || null;
}

function findCodeUsage_(states, candidateCode) {
  for (let index = 0; index < states.length; index += 1) {
    const room = states[index];

    if (room.previousCode === candidateCode) {
      return {
        roomId: room.id,
        roomName: room.name,
        field: 'Previous code'
      };
    }

    if (room.actualCode === candidateCode) {
      return {
        roomId: room.id,
        roomName: room.name,
        field: 'Actual code'
      };
    }
  }

  return null;
}

/**
 * First access on a new date:
 * Previous code = Actual code
 * Previous code date = Actual code date
 * Today changed count = 0
 */
function normalizeRoomsForToday_(roomsSheet, historySheet) {
  const properties = PropertiesService.getScriptProperties();
  const today = getTodayKey_();

  if (properties.getProperty(CONFIG.DAILY_NORMALIZED_KEY) === today) {
    return false;
  }

  const states = readRoomStates_(roomsSheet);
  const now = new Date();
  const changedRooms = [];

  states.forEach(function(room) {
    if (room.stateDate !== today) {
      room.previousCode = room.actualCode;
      room.previousCodeDate = room.actualCodeDate;
      room.stateDate = today;
      room.todayChangedCount = 0;
      room.updatedAt = now;
      changedRooms.push(room);
    }
  });

  if (changedRooms.length) {
    CacheService
      .getScriptCache()
      .remove(CONFIG.ROOMS_CACHE_KEY);

    roomsSheet
      .getRange(2, 1, states.length, 9)
      .setValues(
        states.map(function(room) {
          return [
            room.id,
            room.name,
            room.previousCode,
            room.previousCodeDate,
            room.actualCode,
            room.actualCodeDate,
            room.stateDate,
            room.todayChangedCount,
            room.updatedAt
          ];
        })
      );

    appendHistoryBatch_(
      historySheet,
      changedRooms.map(function(room) {
        return {
          timestamp: now,
          roomId: room.id,
          roomName: room.name,
          previousCode: room.actualCode,
          previousCodeDate: room.actualCodeDate,
          actualCode: room.actualCode,
          actualCodeDate: room.actualCodeDate,
          changed: false,
          todayChangedCount: 0,
          sequencePosition: '',
          sequenceTotal: '',
          eventType: 'DAILY_BASELINE',
          stateDate: today
        };
      })
    );
  }

  properties.setProperty(CONFIG.DAILY_NORMALIZED_KEY, today);
  return changedRooms.length > 0;
}

function appendHistory_(historySheet, eventData) {
  appendHistoryBatch_(historySheet, [eventData]);
}

function appendHistoryBatch_(historySheet, events) {
  if (!events.length) {
    return;
  }

  const startRow = historySheet.getLastRow() + 1;

  historySheet
    .getRange(startRow, 1, events.length, 13)
    .setValues(
      events.map(function(eventData) {
        return [
          eventData.timestamp,
          eventData.roomId,
          eventData.roomName,
          eventData.previousCode,
          eventData.previousCodeDate,
          eventData.actualCode,
          eventData.actualCodeDate,
          eventData.changed,
          eventData.todayChangedCount,
          eventData.sequencePosition,
          eventData.sequenceTotal,
          eventData.eventType,
          eventData.stateDate
        ];
      })
    );
}

function ensureRoomsSheet_(ss) {
  let sheet = ss.getSheetByName(CONFIG.ROOMS_SHEET);

  if (!sheet) {
    sheet = ss.insertSheet(CONFIG.ROOMS_SHEET);
  }

  migrateRoomsSheet_(sheet);

  const existingIds =
    sheet.getLastRow() > 1
      ? sheet
          .getRange(2, 1, sheet.getLastRow() - 1, 1)
          .getDisplayValues()
          .flat()
          .map(String)
      : [];

  const missingRooms = DEFAULT_ROOMS.filter(function(room) {
    return existingIds.indexOf(room.id) === -1;
  });

  if (missingRooms.length) {
    const startRow = sheet.getLastRow() + 1;

    sheet
      .getRange(startRow, 1, missingRooms.length, 9)
      .setValues(
        missingRooms.map(function(room) {
          return [room.id, room.name, '', '', '', '', '', 0, ''];
        })
      );
  }

  sheet.getRange('C:F').setNumberFormat('@');
  return sheet;
}

/**
 * Migrates older room sheets without deleting saved codes.
 */
function migrateRoomsSheet_(sheet) {
  if (sheet.getLastRow() === 0) {
    writeRoomsHeader_(sheet);
    return;
  }

  const lastRow = sheet.getLastRow();
  const lastColumn = Math.max(sheet.getLastColumn(), 9);
  const headers = sheet
    .getRange(1, 1, 1, lastColumn)
    .getDisplayValues()[0]
    .map(function(value) {
      return String(value).trim().toLowerCase();
    });

  const headerIndex = {};

  headers.forEach(function(header, index) {
    if (header) {
      headerIndex[header] = index;
    }
  });

  const isCurrentLayout =
    headerIndex['previous code'] !== undefined &&
    headerIndex['previous code date'] !== undefined &&
    headerIndex['actual code'] !== undefined &&
    headerIndex['actual code date'] !== undefined &&
    headerIndex['state date'] !== undefined &&
    headerIndex['today changed count'] !== undefined;

  if (isCurrentLayout) {
    writeRoomsHeader_(sheet);
    return;
  }

  const sourceRows =
    lastRow > 1
      ? sheet.getRange(2, 1, lastRow - 1, lastColumn).getValues()
      : [];

  function valueByHeader_(row, header) {
    const index = headerIndex[header];
    return index === undefined ? '' : row[index];
  }

  const migrated = sourceRows.map(function(row) {
    const roomId =
      String(valueByHeader_(row, 'room id') || row[0] || '');
    const roomName =
      String(valueByHeader_(row, 'room name') || row[1] || '');

    const previousCode = normalizeCodeValue_(
      valueByHeader_(row, 'previous code')
    );
    const actualCode = normalizeCodeValue_(
      valueByHeader_(row, 'actual code')
    );

    const stateDate = normalizeDateKey_(
      valueByHeader_(row, 'state date')
    );

    const updatedAt =
      valueByHeader_(row, 'updated at') || '';

    const previousCodeDate =
      normalizeDateKey_(
        valueByHeader_(row, 'previous code date')
      ) ||
      stateDate ||
      dateKeyFromValue_(updatedAt);

    const actualCodeDate =
      normalizeDateKey_(
        valueByHeader_(row, 'actual code date')
      ) ||
      stateDate ||
      dateKeyFromValue_(updatedAt);

    return [
      roomId,
      roomName,
      previousCode,
      previousCodeDate,
      actualCode,
      actualCodeDate,
      stateDate,
      Number(
        valueByHeader_(row, 'today changed count')
      ) || 0,
      updatedAt
    ];
  });

  sheet.clear();
  writeRoomsHeader_(sheet);

  if (migrated.length) {
    sheet.getRange(2, 1, migrated.length, 9).setValues(migrated);
  }
}

function writeRoomsHeader_(sheet) {
  sheet
    .getRange(1, 1, 1, 9)
    .setValues([[
      'Room ID',
      'Room name',
      'Previous code',
      'Previous code date',
      'Actual code',
      'Actual code date',
      'State date',
      'Today changed count',
      'Updated at'
    ]]);
}

function ensureHistorySheet_(ss) {
  let sheet = ss.getSheetByName(CONFIG.HISTORY_SHEET);

  if (!sheet) {
    sheet = ss.insertSheet(CONFIG.HISTORY_SHEET);
  }

  sheet
    .getRange(1, 1, 1, 13)
    .setValues([[
      'Timestamp',
      'Room ID',
      'Room name',
      'Previous code',
      'Previous code date',
      'Actual code',
      'Actual code date',
      'Changed',
      'Today changed count',
      'Sequence position',
      'Sequence total',
      'Event type',
      'State date'
    ]]);

  sheet.getRange('D:G').setNumberFormat('@');
  return sheet;
}

function readRoomStates_(roomsSheet) {
  const lastRow = roomsSheet.getLastRow();

  if (lastRow < 2) {
    return [];
  }

  return roomsSheet
    .getRange(2, 1, lastRow - 1, 9)
    .getValues()
    .filter(function(row) {
      return String(row[0]).trim() !== '';
    })
    .map(function(row, index) {
      return {
        rowNumber: index + 2,
        id: String(row[0]),
        name: String(row[1]),
        previousCode: formatStoredCode_(row[2]),
        previousCodeDate: normalizeDateKey_(row[3]),
        actualCode: formatStoredCode_(row[4]),
        actualCodeDate: normalizeDateKey_(row[5]),
        stateDate: normalizeDateKey_(row[6]),
        todayChangedCount: Number(row[7]) || 0,
        updatedAt: row[8] || ''
      };
    });
}

function serializeRooms_(rooms) {
  return rooms.map(serializeRoom_);
}

function serializeRoom_(room) {
  return {
    id: room.id,
    name: room.name,
    previousCode: room.previousCode,
    previousCodeDate: room.previousCodeDate,
    previousCodeDateDisplay: formatDisplayDate_(room.previousCodeDate),
    actualCode: room.actualCode,
    actualCodeDate: room.actualCodeDate,
    actualCodeDateDisplay: formatDisplayDate_(room.actualCodeDate),
    stateDate: room.stateDate,
    todayChangedCount: room.todayChangedCount,
    changed:
      room.todayChangedCount > 0 &&
      room.actualCode !== '' &&
      room.actualCode !== room.previousCode
  };
}

function getGeneratorData_() {
  const ss = getSpreadsheet_();
  const codesSheet = ss.getSheetByName(CONFIG.CODES_SHEET);
  const roomsSheet = ss.getSheetByName(CONFIG.ROOMS_SHEET);
  const historySheet = ss.getSheetByName(CONFIG.HISTORY_SHEET);
  const usedCodesSheet = ss.getSheetByName(CONFIG.USED_CODES_SHEET);

  if (
    !codesSheet ||
    !roomsSheet ||
    !historySheet ||
    !usedCodesSheet
  ) {
    throw new Error(
      'The data sheets are not configured. Open the spreadsheet and run setup().'
    );
  }

  const codes = getCodeList_(codesSheet);

  if (!codes.length) {
    throw new Error('The code list is empty.');
  }

  const properties = PropertiesService.getScriptProperties();
  const index = Number(properties.getProperty(CONFIG.INDEX_KEY) || 0);
  const usedFlags = getAutoUsedFlags_(
    properties,
    codes.length
  );

  return {
    codes: codes,
    codeIndex: buildCodeIndex_(codes),
    usedFlags: usedFlags,
    total: codes.length,
    roomsSheet: roomsSheet,
    historySheet: historySheet,
    usedCodesSheet: usedCodesSheet,
    properties: properties,
    index: index
  };
}


/**
 * Creates a 10,000-row registry:
 * Code | Last used at
 *
 * Row number is deterministic:
 * code 0000 -> row 2
 * code 9999 -> row 10001
 */
function ensureUsedCodesSheet_(ss) {
  let sheet = ss.getSheetByName(CONFIG.USED_CODES_SHEET);

  if (!sheet) {
    sheet = ss.insertSheet(CONFIG.USED_CODES_SHEET);
  }

  sheet
    .getRange(1, 1, 1, 2)
    .setValues([[
      'Code',
      'Last used at'
    ]]);

  const needsCodeColumn =
    sheet.getLastRow() < 10001 ||
    sheet.getRange('A2').getDisplayValue() !== '0000' ||
    sheet.getRange('A10001').getDisplayValue() !== '9999';

  if (needsCodeColumn) {
    const codes = Array.from(
      {length: 10000},
      function(_, index) {
        return [String(index).padStart(4, '0')];
      }
    );

    sheet
      .getRange(2, 1, 10000, 1)
      .setValues(codes);
  }

  sheet.getRange('A:A').setNumberFormat('@');
  sheet.getRange('B:B').setNumberFormat('yyyy-mm-dd hh:mm:ss');

  return sheet;
}

/**
 * One-time migration: recover the most recent real assignment timestamp
 * for each code from History, then supplement it with current room dates.
 *
 * DAILY_BASELINE and REPAIR_BASELINE do not count as code usage.
 */
function syncUsedCodesFromHistory_(
  usedCodesSheet,
  historySheet,
  roomsSheet
) {
  const latest = Array(10000).fill(null);
  const historyLastRow = historySheet.getLastRow();

  if (historyLastRow > 1) {
    const lastColumn = Math.max(
      historySheet.getLastColumn(),
      13
    );

    const headers = historySheet
      .getRange(1, 1, 1, lastColumn)
      .getDisplayValues()[0]
      .map(function(value) {
        return String(value).trim().toLowerCase();
      });

    const headerIndex = {};

    headers.forEach(function(header, index) {
      if (header) {
        headerIndex[header] = index;
      }
    });

    const timestampIndex =
      headerIndex['timestamp'] !== undefined
        ? headerIndex['timestamp']
        : 0;

    const actualCodeIndex =
      headerIndex['actual code'] !== undefined
        ? headerIndex['actual code']
        : 4;

    const eventTypeIndex =
      headerIndex['event type'];

    const rows = historySheet
      .getRange(
        2,
        1,
        historyLastRow - 1,
        lastColumn
      )
      .getValues();

    rows.forEach(function(row) {
      const eventType =
        eventTypeIndex === undefined
          ? ''
          : String(row[eventTypeIndex] || '');

      if (/BASELINE/i.test(eventType)) {
        return;
      }

      const code = normalizeCodeValue_(
        row[actualCodeIndex]
      );

      if (!code) {
        return;
      }

      const timestamp = coerceDate_(row[timestampIndex]);

      if (!timestamp) {
        return;
      }

      const numericCode = Number(code);

      if (
        !latest[numericCode] ||
        timestamp.getTime() >
          latest[numericCode].getTime()
      ) {
        latest[numericCode] = timestamp;
      }
    });
  }

  // Supplement with the persisted room dates where available.
  readRoomStates_(roomsSheet).forEach(function(room) {
    [
      [room.previousCode, room.previousCodeDate],
      [room.actualCode, room.actualCodeDate]
    ].forEach(function(item) {
      const code = normalizeCodeValue_(item[0]);
      const timestamp = dateFromDateKey_(item[1]);

      if (!code || !timestamp) {
        return;
      }

      const numericCode = Number(code);

      if (
        !latest[numericCode] ||
        timestamp.getTime() >
          latest[numericCode].getTime()
      ) {
        latest[numericCode] = timestamp;
      }
    });
  });

  usedCodesSheet
    .getRange(2, 2, 10000, 1)
    .setValues(
      latest.map(function(value) {
        return [value || ''];
      })
    );
}


/**
 * Builds a direct lookup from a four-digit code to its position
 * in the shuffled master list.
 */
function buildCodeIndex_(codes) {
  const result = Object.create(null);

  codes.forEach(function(code, index) {
    result[String(code).padStart(4, '0')] = index;
  });

  return result;
}

/**
 * Returns the most recent use date stored for one code.
 */
function getCodeLastUsedAt_(usedCodesSheet, code) {
  const normalizedCode = normalizeCodeValue_(code);

  if (!normalizedCode) {
    return null;
  }

  return coerceDate_(
    usedCodesSheet
      .getRange(Number(normalizedCode) + 2, 2)
      .getValue()
  );
}

/**
 * Saves the most recent real assignment timestamp for one code.
 */
function markCodeUsed_(usedCodesSheet, code, usedAt) {
  const normalizedCode = normalizeCodeValue_(code);

  if (!normalizedCode) {
    throw new Error('The assigned code is invalid.');
  }

  const timestamp = coerceDate_(usedAt) || new Date();

  usedCodesSheet
    .getRange(Number(normalizedCode) + 2, 2)
    .setValue(timestamp);
}

/**
 * Manual-input rule:
 * returns true only when the code was used during the previous 30 days.
 */
function isWithinUsageWindow_(value) {
  const usedAt = coerceDate_(value);

  if (!usedAt) {
    return false;
  }

  const windowMilliseconds =
    CONFIG.USED_WINDOW_DAYS *
    24 *
    60 *
    60 *
    1000;

  return usedAt.getTime() >=
    Date.now() - windowMilliseconds;
}

/**
 * Converts a spreadsheet value into a valid Date, or null.
 */
function coerceDate_(value) {
  if (!value) {
    return null;
  }

  if (value instanceof Date) {
    return isNaN(value.getTime())
      ? null
      : value;
  }

  const parsed = new Date(value);

  return isNaN(parsed.getTime())
    ? null
    : parsed;
}

/**
 * Converts yyyy-MM-dd into a local midday Date.
 * Midday avoids daylight-saving boundary problems.
 */
function dateFromDateKey_(dateKey) {
  const normalized = normalizeDateKey_(dateKey);

  if (!normalized) {
    return null;
  }

  const parts = normalized.split('-');

  if (parts.length !== 3) {
    return null;
  }

  const result = new Date(
    Number(parts[0]),
    Number(parts[1]) - 1,
    Number(parts[2]),
    12,
    0,
    0
  );

  return isNaN(result.getTime())
    ? null
    : result;
}

/**
 * Serializes a timestamp for diagnostic responses.
 */
function serializeDateTime_(value) {
  const date = coerceDate_(value);

  if (!date) {
    return '';
  }

  const timeZone =
    Session.getScriptTimeZone() ||
    'Europe/Lisbon';

  return Utilities.formatDate(
    date,
    timeZone,
    'yyyy-MM-dd HH:mm:ss'
  );
}

/**
 * AUTO CURRENT-CYCLE LIST
 *
 * The 30-day rule is not applied to Auto.
 * Auto skips only codes already consumed in its current cycle.
 * A master-list code entered manually is also marked consumed in that cycle.
 */
function initializeAutoUsageState_(
  codes,
  roomsSheet,
  properties
) {
  const stored = properties.getProperty(
    CONFIG.AUTO_USED_FLAGS_KEY
  );

  let flags;

  if (
    stored &&
    stored.length === codes.length &&
    /^[01]+$/.test(stored)
  ) {
    flags = stored.split('');
  } else {
    flags = Array(codes.length).fill('0');

    const existingIndex = normalizeIndex_(
      Number(properties.getProperty(CONFIG.INDEX_KEY) || 0),
      codes.length
    );

    // Preserve progress when migrating from the older sequential version.
    for (let index = 0; index < existingIndex; index += 1) {
      flags[index] = '1';
    }
  }

  markVisibleRoomCodesInAutoCycle_(
    flags,
    buildCodeIndex_(codes),
    readRoomStates_(roomsSheet)
  );

  saveAutoUsedFlags_(properties, flags);
}

function getAutoUsedFlags_(properties, total) {
  const stored = properties.getProperty(
    CONFIG.AUTO_USED_FLAGS_KEY
  );

  if (
    stored &&
    stored.length === total &&
    /^[01]+$/.test(stored)
  ) {
    return stored.split('');
  }

  const flags = Array(total).fill('0');
  saveAutoUsedFlags_(properties, flags);
  return flags;
}

function saveAutoUsedFlags_(properties, flags) {
  properties.setProperty(
    CONFIG.AUTO_USED_FLAGS_KEY,
    flags.join('')
  );
}

function markVisibleRoomCodesInAutoCycle_(
  flags,
  codeIndex,
  states
) {
  states.forEach(function(room) {
    [room.previousCode, room.actualCode].forEach(function(code) {
      const index = codeIndex[code];

      if (index !== undefined) {
        flags[index] = '1';
      }
    });
  });
}

/**
 * Finds the next unused code in the current Auto cycle.
 *
 * When every master code has been consumed, a fresh cycle starts.
 * Codes still visible in room Previous/Actual remain blocked at cycle reset.
 */
function resolveNextUnusedCode_(data, states) {
  let startIndex = normalizeIndex_(
    data.index,
    data.total
  );

  let index = findNextUnusedIndex_(
    data.usedFlags,
    startIndex
  );

  if (index === -1) {
    data.usedFlags = Array(data.total).fill('0');

    markVisibleRoomCodesInAutoCycle_(
      data.usedFlags,
      data.codeIndex,
      states
    );

    saveAutoUsedFlags_(
      data.properties,
      data.usedFlags
    );

    startIndex = 0;
    index = findNextUnusedIndex_(
      data.usedFlags,
      startIndex
    );
  }

  if (index === -1) {
    throw new Error(
      'No automatic code is currently available.'
    );
  }

  data.index = index;

  data.properties.setProperty(
    CONFIG.INDEX_KEY,
    String(index)
  );

  return {
    index: index,
    code: data.codes[index]
  };
}

function findNextUnusedIndex_(flags, startIndex) {
  const total = flags.length;

  for (let offset = 0; offset < total; offset += 1) {
    const index = (startIndex + offset) % total;

    if (flags[index] !== '1') {
      return index;
    }
  }

  return -1;
}



/**
 * Six room rows are cached after the first read.
 * Normal Web App saves therefore avoid rereading the Rooms sheet.
 */
function getRoomStatesCached_(roomsSheet) {
  const cache = CacheService.getScriptCache();
  const cached = cache.get(CONFIG.ROOMS_CACHE_KEY);

  if (cached) {
    try {
      return JSON.parse(cached);
    } catch (error) {
      cache.remove(CONFIG.ROOMS_CACHE_KEY);
    }
  }

  const states = readRoomStates_(roomsSheet);
  cacheRoomStates_(states);
  return states;
}

function cacheRoomStates_(states) {
  CacheService
    .getScriptCache()
    .put(
      CONFIG.ROOMS_CACHE_KEY,
      JSON.stringify(states),
      21600
    );
}

/** Cache all codes to remove the spreadsheet read from normal clicks. */
function getCodeList_(codesSheet) {
  const cache = CacheService.getScriptCache();
  const cached = cache.get(CONFIG.CODES_CACHE_KEY);

  if (cached) {
    try {
      return JSON.parse(cached);
    } catch (error) {
      cache.remove(CONFIG.CODES_CACHE_KEY);
    }
  }

  const lastRow = codesSheet.getLastRow();

  if (lastRow < 1) {
    return [];
  }

  const codes = codesSheet
    .getRange(1, 1, lastRow, 1)
    .getDisplayValues()
    .flat()
    .filter(function(value) {
      return String(value).trim() !== '';
    })
    .map(function(value) {
      return String(value).padStart(4, '0');
    });

  cache.put(CONFIG.CODES_CACHE_KEY, JSON.stringify(codes), 21600);
  return codes;
}

function buildPreview_(codes, index) {
  return {
    index: index,
    code: codes[index],
    position: index + 1,
    total: codes.length
  };
}

function formatDisplayDate_(dateKey) {
  const normalized = normalizeDateKey_(dateKey);

  if (!normalized) {
    return '';
  }

  const parts = normalized.split('-');
  return parts.length === 3
    ? parts[2] + '-' + parts[1] + '-' + parts[0]
    : normalized;
}

function dateKeyFromValue_(value) {
  if (!value) {
    return '';
  }

  if (value instanceof Date) {
    const timeZone = Session.getScriptTimeZone() || 'Europe/Lisbon';
    return Utilities.formatDate(value, timeZone, 'yyyy-MM-dd');
  }

  return normalizeDateKey_(value);
}

function getSpreadsheet_() {
  const spreadsheetId = PropertiesService
    .getScriptProperties()
    .getProperty(CONFIG.SPREADSHEET_ID_KEY);

  if (!spreadsheetId) {
    throw new Error(
      'The spreadsheet is not configured. Open it, run setup(), and deploy again.'
    );
  }

  return SpreadsheetApp.openById(spreadsheetId);
}


function normalizeCodeValue_(value) {
  if (value === null || value === '') {
    return '';
  }

  if (value instanceof Date) {
    return '';
  }

  if (
    typeof value === 'number' &&
    Number.isFinite(value) &&
    Number.isInteger(value) &&
    value >= 0 &&
    value <= 9999
  ) {
    return String(value).padStart(4, '0');
  }

  const text = String(value).trim();

  if (!/^\d{1,4}$/.test(text)) {
    return '';
  }

  return text.padStart(4, '0');
}

function isValidCodeValue_(value) {
  return normalizeCodeValue_(value) !== '';
}

function isInvalidNonEmptyCodeValue_(value) {
  return (
    value !== null &&
    String(value).trim() !== '' &&
    !isValidCodeValue_(value)
  );
}

function formatStoredCode_(value) {
  return normalizeCodeValue_(value);
}



/**
 * Repairs room rows whose code columns contain dates or invalid values.
 * The latest valid assignment is recovered from History.
 *
 * After repair, Previous code and Actual code are set equal as today's
 * baseline. This function does not consume a sequence code.
 */
function repairCorruptedRoomCodesFromHistory() {
  const lock = LockService.getScriptLock();
  lock.waitLock(10000);

  try {
    const ss = getSpreadsheet_();
    const roomsSheet = ss.getSheetByName(CONFIG.ROOMS_SHEET);
    const historySheet = ss.getSheetByName(CONFIG.HISTORY_SHEET);

    if (!roomsSheet || !historySheet) {
      throw new Error(
        'The "Rooms" and "History" sheets are required.'
      );
    }

    const lastRow = roomsSheet.getLastRow();

    if (lastRow < 2) {
      throw new Error('There are no rooms to repair.');
    }

    const recovered =
      recoverLatestAssignmentsFromHistory_(historySheet);

    const rows = roomsSheet
      .getRange(2, 1, lastRow - 1, 9)
      .getValues();

    const today = getTodayKey_();
    const now = new Date();
    const repairedRoomIds = [];
    const unresolvedRoomIds = [];
    const repairEvents = [];

    rows.forEach(function(row) {
      const roomId = String(row[0] || '');
      const roomName = String(row[1] || '');

      const previousIsInvalid =
        isInvalidNonEmptyCodeValue_(row[2]);
      const actualIsInvalid =
        isInvalidNonEmptyCodeValue_(row[4]);

      if (!previousIsInvalid && !actualIsInvalid) {
        return;
      }

      const recovery = recovered[roomId];

      if (!recovery || !recovery.actualCode) {
        if (previousIsInvalid) {
          row[2] = '';
          row[3] = '';
        }

        if (actualIsInvalid) {
          row[4] = '';
          row[5] = '';
        }

        row[6] = today;
        row[7] = 0;
        row[8] = now;
        unresolvedRoomIds.push(roomId);
        return;
      }

      row[2] = recovery.actualCode;
      row[3] = recovery.actualCodeDate;
      row[4] = recovery.actualCode;
      row[5] = recovery.actualCodeDate;
      row[6] = today;
      row[7] = 0;
      row[8] = now;

      repairedRoomIds.push(roomId);

      repairEvents.push({
        timestamp: now,
        roomId: roomId,
        roomName: roomName,
        previousCode: recovery.actualCode,
        previousCodeDate: recovery.actualCodeDate,
        actualCode: recovery.actualCode,
        actualCodeDate: recovery.actualCodeDate,
        changed: false,
        todayChangedCount: 0,
        sequencePosition: '',
        sequenceTotal: '',
        eventType: 'REPAIR_BASELINE',
        stateDate: today
      });
    });

    roomsSheet
      .getRange(2, 1, rows.length, 9)
      .setValues(rows);

    if (repairEvents.length) {
      appendHistoryBatch_(historySheet, repairEvents);
    }

    const repairProperties =
      PropertiesService.getScriptProperties();

    repairProperties.setProperty(
      CONFIG.DAILY_NORMALIZED_KEY,
      today
    );

    CacheService
      .getScriptCache()
      .remove(CONFIG.ROOMS_CACHE_KEY);

    SpreadsheetApp.flush();

    return {
      ok: true,
      repairedRooms: repairedRoomIds,
      unresolvedRooms: unresolvedRoomIds,
      message:
        repairedRoomIds.length +
        ' room(s) repaired; ' +
        unresolvedRoomIds.length +
        ' room(s) could not be recovered from History.'
    };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Supports previous History layouts:
 * Old: D Previous code, E Actual code
 * New: D Previous code, E Previous date, F Actual code, G Actual date
 */
function recoverLatestAssignmentsFromHistory_(historySheet) {
  const lastRow = historySheet.getLastRow();
  const result = {};

  if (lastRow < 2) {
    return result;
  }

  const lastColumn = Math.max(historySheet.getLastColumn(), 13);
  const rows = historySheet
    .getRange(2, 1, lastRow - 1, lastColumn)
    .getValues();

  for (let index = rows.length - 1; index >= 0; index -= 1) {
    const row = rows[index];
    const roomId = String(row[1] || '');

    if (!roomId || result[roomId]) {
      continue;
    }

    const previousCandidate = normalizeCodeValue_(row[3]);
    const oldActualCandidate = normalizeCodeValue_(row[4]);
    const newActualCandidate = normalizeCodeValue_(row[5]);

    let actualCode = '';
    let actualCodeDate = '';

    if (newActualCandidate) {
      actualCode = newActualCandidate;
      actualCodeDate =
        normalizeDateKey_(row[6]) ||
        dateKeyFromValue_(row[0]);
    } else if (oldActualCandidate) {
      actualCode = oldActualCandidate;
      actualCodeDate = dateKeyFromValue_(row[0]);
    }

    if (!actualCode) {
      continue;
    }

    result[roomId] = {
      previousCode: previousCandidate || actualCode,
      actualCode: actualCode,
      actualCodeDate:
        actualCodeDate ||
        dateKeyFromValue_(row[0]) ||
        getTodayKey_()
    };
  }

  return result;
}

/**
 * TEST ONLY — simulates the transition to a new date.
 *
 * It does not change the computer, phone, or server clock.
 * Instead, it marks every room as belonging to yesterday and clears the
 * daily-normalization flag. On the next Web App reload, the normal production
 * algorithm runs:
 *
 * Previous code = Actual code
 * Previous code date = Actual code date
 * Today changed count = 0
 */
function simulateNewDayForTest() {
  const ss = getSpreadsheet_();
  const roomsSheet = ss.getSheetByName(CONFIG.ROOMS_SHEET);

  if (!roomsSheet) {
    throw new Error('The "Rooms" sheet could not be found.');
  }

  const roomCount = roomsSheet.getLastRow() - 1;

  if (roomCount < 1) {
    throw new Error('There are no rooms to test.');
  }

  const codeValues = roomsSheet
    .getRange(2, 3, roomCount, 3)
    .getValues();

  const invalidRows = [];

  codeValues.forEach(function(row, index) {
    if (
      isInvalidNonEmptyCodeValue_(row[0]) ||
      isInvalidNonEmptyCodeValue_(row[2])
    ) {
      invalidRows.push(index + 2);
    }
  });

  if (invalidRows.length) {
    throw new Error(
      'Invalid code data was found in Rooms row(s): ' +
      invalidRows.join(', ') +
      '. Run repairCorruptedRoomCodesFromHistory() first.'
    );
  }

  const timeZone =
    Session.getScriptTimeZone() ||
    'Europe/Lisbon';

  const yesterday = new Date(
    Date.now() - 24 * 60 * 60 * 1000
  );

  const yesterdayKey = Utilities.formatDate(
    yesterday,
    timeZone,
    'yyyy-MM-dd'
  );

  // Only column G (Daily state date) is changed.
  roomsSheet
    .getRange(2, 7, roomCount, 1)
    .setValues(
      Array.from(
        {length: roomCount},
        function() {
          return [yesterdayKey];
        }
      )
    );

  PropertiesService
    .getScriptProperties()
    .deleteProperty(CONFIG.DAILY_NORMALIZED_KEY);

  CacheService
    .getScriptCache()
    .remove(CONFIG.ROOMS_CACHE_KEY);

  SpreadsheetApp.flush();

  return {
    ok: true,
    simulatedPreviousDate: yesterdayKey,
    message:
      'New-day simulation prepared. Reload the Web App.'
  };
}

function getTodayKey_() {
  const timeZone =
    Session.getScriptTimeZone() ||
    'Europe/Lisbon';

  return Utilities.formatDate(
    new Date(),
    timeZone,
    'yyyy-MM-dd'
  );
}

function normalizeDateKey_(value) {
  if (!value) {
    return '';
  }

  if (value instanceof Date) {
    const timeZone =
      Session.getScriptTimeZone() ||
      'Europe/Lisbon';

    return Utilities.formatDate(
      value,
      timeZone,
      'yyyy-MM-dd'
    );
  }

  return String(value).slice(0, 10);
}

function normalizeIndex_(index, total) {
  return Number.isInteger(index) && index >= 0 && index < total ? index : 0;
}

function protectSheet_(sheet, description) {
  const existing = sheet
    .getProtections(SpreadsheetApp.ProtectionType.SHEET)
    .find(function(protection) {
      return protection.getDescription() === description &&
        protection.canEdit();
    });

  const protection =
    existing || sheet.protect().setDescription(description);

  protection.setWarningOnly(false);
  protection.setUnprotectedRanges([]);

  const effectiveUser = Session.getEffectiveUser();
  const effectiveEmail = effectiveUser.getEmail();

  protection.addEditor(effectiveUser);

  const removableEditors = protection
    .getEditors()
    .filter(function(user) {
      return !effectiveEmail || user.getEmail() !== effectiveEmail;
    });

  if (removableEditors.length) {
    protection.removeEditors(removableEditors);
  }

  if (protection.canDomainEdit()) {
    protection.setDomainEdit(false);
  }
}
