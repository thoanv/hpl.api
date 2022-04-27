CREATE TABLE `hpl_clipboard_signatures`
(
    `ID`            int(11) NOT NULL AUTO_INCREMENT,
    `ID_ROW`        int(11),
    `STAGE_ID`      int(11) default 0,
    `PREVIOUS_STAGE_ID` int(11) default 0,
    `XML_ID`        text(),
    `CREATED_BY`    int(11) default 0,
    `UPDATED_BY`    int(11) default 0,
    `MOVED_BY`      int(11) default 0,
    `CREATED_TIME`  datetime,
    `UPDATED_TIME`  datetime,
    `MOVED_TIME`    datetime,
    `NAME_TASK`     varchar(255),
    `ID_TASK`       int(11) default 0,
    `IS_SIGN`       int(11) default 1,
    `NOTE`          text(),
    `FILES`         text(),
    `SIGN_1`        int(11) default 0,
    `SIGN_2`        int(11) default 0,
    `SIGN_3`        int(11) default 0,
    `SIGN_4`        int(11) default 0,
    `SIGN_5`        int(11) default 0,
    PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;