<?php

declare(strict_types=1);

namespace MeadBotApi\Calculator;

/**
 * Ported from MeadBot's src/calculator/CalculatorAPI.Constants.js.
 * Values here must stay in sync with that file.
 */
final class Constants
{
    // error types
    public const ERROR_UNKNOWN = 0;
    public const ERROR_IS_NAN = 1;
    public const ERROR_RANGE = 2;
    public const ERROR_INVALID_ARGUMENTS = 3;

    public const ERROR_TYPE_STRINGS = [
        self::ERROR_UNKNOWN => 'unknown',
        self::ERROR_IS_NAN => 'isNaN',
        self::ERROR_RANGE => 'range',
        self::ERROR_INVALID_ARGUMENTS => 'invalid arguments',
    ];

    // volume unit identifiers
    public const VOLUME_UNIT_LITERS = 0;
    public const VOLUME_UNIT_GALLONS_US = 1;
    public const VOLUME_UNIT_GALLONS_IMP = 2;
    public const VOLUME_UNIT_FL_OUNCES_US = 3;
    public const VOLUME_UNIT_FL_OUNCES_IMP = 4;
    public const VOLUME_UNIT_PINTS_US = 5;
    public const VOLUME_UNIT_PINTS_IMP = 6;
    public const VOLUME_UNIT_QUARTS_US = 7;
    public const VOLUME_UNIT_QUARTS_IMP = 8;
    public const VOLUME_UNIT_CUPS_US = 9;
    public const VOLUME_UNIT_CUPS_IMP = 10;
    public const VOLUME_UNIT_CUPS_METRIC = 11;

    public const VOLUME_UNIT_INFO = [
        self::VOLUME_UNIT_LITERS => ['name' => 'Liter(s)', 'conversion' => 1.0],
        self::VOLUME_UNIT_GALLONS_US => ['name' => 'Gallon(s) US', 'conversion' => 3.7854117891],
        self::VOLUME_UNIT_GALLONS_IMP => ['name' => 'Gallon(s) Imp', 'conversion' => 4.546091886873],
        self::VOLUME_UNIT_FL_OUNCES_US => ['name' => 'Fl Ounce(s) US', 'conversion' => 0.0295735295641118736222],
        self::VOLUME_UNIT_FL_OUNCES_IMP => ['name' => 'Fl Ounce(s) Imp', 'conversion' => 0.0284130742283722207737],
        self::VOLUME_UNIT_PINTS_US => ['name' => 'Pint(s) US', 'conversion' => 0.473176472745920009839],
        self::VOLUME_UNIT_PINTS_IMP => ['name' => 'Pint(s) Imp', 'conversion' => 0.5682614845674444154745],
        self::VOLUME_UNIT_QUARTS_US => ['name' => 'Quart(s) US', 'conversion' => 0.9463529454918400196781],
        self::VOLUME_UNIT_QUARTS_IMP => ['name' => 'Quart(s) Imp', 'conversion' => 1.1365229691348888309489],
        self::VOLUME_UNIT_CUPS_US => ['name' => 'Cup(s) US', 'conversion' => 0.2365882363729600049195],
        self::VOLUME_UNIT_CUPS_IMP => ['name' => 'Cup(s) Imp', 'conversion' => 0.284130624982675667887],
        self::VOLUME_UNIT_CUPS_METRIC => ['name' => 'Cup(s) Metric', 'conversion' => 0.25],
    ];

    // honey unit identifiers
    public const HONEY_UNIT_KILOGRAMS = 0;
    public const HONEY_UNIT_POUNDS = 1;
    public const HONEY_UNIT_LITERS = 2;
    public const HONEY_UNIT_GALLONS_US = 3;
    public const HONEY_UNIT_GALLONS_IMP = 4;
    public const HONEY_UNIT_OUNCES = 5;
    public const HONEY_UNIT_CUPS_US = 6;
    public const HONEY_UNIT_CUPS_IMP = 7;
    public const HONEY_UNIT_CUPS_METRIC = 8;
    public const HONEY_UNIT_FL_OUNCES_US = 9;
    public const HONEY_UNIT_FL_OUNCES_IMP = 10;
    public const HONEY_UNIT_PINTS_US = 11;
    public const HONEY_UNIT_PINTS_IMP = 12;
    public const HONEY_UNIT_QUARTS_US = 13;
    public const HONEY_UNIT_QUARTS_IMP = 14;

    public const HONEY_UNIT_INFO = [
        self::HONEY_UNIT_KILOGRAMS => ['name' => 'Kilogram(s)', 'conversion' => 1.0],
        self::HONEY_UNIT_POUNDS => ['name' => 'Pound(s)', 'conversion' => 0.45359237038],
        self::HONEY_UNIT_LITERS => ['name' => 'Liter(s)', 'conversion' => 1.4379171305280134085],
        self::HONEY_UNIT_GALLONS_US => ['name' => 'Gallon(s) US', 'conversion' => 5.44310844456453957639],
        self::HONEY_UNIT_GALLONS_IMP => ['name' => 'Gallon(s) Imp', 'conversion' => 6.5369033865223141589149],
        self::HONEY_UNIT_OUNCES => ['name' => 'Ounce(s)', 'conversion' => 0.0283499767411440240053],
        self::HONEY_UNIT_CUPS_US => ['name' => 'Cup(s) US', 'conversion' => 0.3401942777852837235244],
        self::HONEY_UNIT_CUPS_IMP => ['name' => 'Cup(s) Imp', 'conversion' => 0.4085562923813757040209],
        self::HONEY_UNIT_CUPS_METRIC => ['name' => 'Cup(s) Metric', 'conversion' => 0.359479282252145832623],
        self::HONEY_UNIT_FL_OUNCES_US => ['name' => 'Fl Ounce(s) US', 'conversion' => 0.0425242847231604654406],
        self::HONEY_UNIT_FL_OUNCES_IMP => ['name' => 'Fl Ounce(s) Imp', 'conversion' => 0.0408556461657641887027],
        self::HONEY_UNIT_PINTS_US => ['name' => 'Pint(s) US', 'conversion' => 0.6803885555705674470491],
        self::HONEY_UNIT_PINTS_IMP => ['name' => 'Pint(s) Imp', 'conversion' => 0.8171129233152837740547],
        self::HONEY_UNIT_QUARTS_US => ['name' => 'Quart(s) US', 'conversion' => 1.3607771111411348940981],
        self::HONEY_UNIT_QUARTS_IMP => ['name' => 'Quart(s) Imp', 'conversion' => 1.6342258466305675481094],
    ];

    // temperature unit identifiers
    public const TEMPERATURE_UNIT_CELSIUS = 0;
    public const TEMPERATURE_UNIT_FAHRENHEIT = 1;

    public const TEMPERATURE_UNIT_NAMES = [
        self::TEMPERATURE_UNIT_CELSIUS => 'Celsius',
        self::TEMPERATURE_UNIT_FAHRENHEIT => 'Fahrenheit',
    ];

    // gravity unit identifiers
    public const GRAVITY_UNIT_SG = 0;
    public const GRAVITY_UNIT_BRIX = 1;
    public const GRAVITY_UNIT_BAUME = 2;

    public const GRAVITY_UNIT_NAMES = [
        self::GRAVITY_UNIT_SG => 'SG',
        self::GRAVITY_UNIT_BRIX => 'BRIX',
        self::GRAVITY_UNIT_BAUME => 'Baume',
    ];

    // alcohol content unit identifiers
    public const ABV_UNIT_ABV = 0;
    public const ABV_UNIT_ABW = 1;

    public const ABV_UNIT_NAMES = [
        self::ABV_UNIT_ABV => '%ABV',
        self::ABV_UNIT_ABW => '%ABW',
    ];

    // units identifiers for the blending calculator
    public const BLEND_UNIT_SG = 0;
    public const BLEND_UNIT_BRIX = 1;
    public const BLEND_UNIT_BAUME = 2;
    public const BLEND_UNIT_ABV = 3;
    public const BLEND_UNIT_ABW = 4;
    public const BLEND_UNIT_OTHER = 5;

    public const BLEND_UNIT_NAMES = [
        self::BLEND_UNIT_SG => 'SG',
        self::BLEND_UNIT_BRIX => 'BRIX',
        self::BLEND_UNIT_BAUME => 'Baume',
        self::BLEND_UNIT_ABV => '%ABV',
        self::BLEND_UNIT_ABW => '%ABW',
        self::BLEND_UNIT_OTHER => 'Value',
    ];

    // field identifiers for the blending calculator
    public const BLEND_FIELD_VALUE1 = 0;
    public const BLEND_FIELD_VALUE2 = 1;
    public const BLEND_FIELD_BLENDED_VALUE = 2;
    public const BLEND_FIELD_VOLUME1 = 3;
    public const BLEND_FIELD_VOLUME2 = 4;
    public const BLEND_FIELD_TOTAL_VOLUME = 5;

    // sugar source identifiers that index into SUGAR_SOURCE_INFO
    public const SUGAR_SOURCES = [
        'HONEY' => 0, 'SUGAR' => 1, 'ACEROLA' => 2, 'APPLES' => 3, 'APRICOTS' => 4,
        'APRICOTS_DRIED' => 5, 'BANANAS' => 6, 'BLACKBERRY' => 7, 'BLUEBERRY' => 8, 'BOYSENBERRY' => 9,
        'CANTALOUPE' => 10, 'CARAMBOLA' => 11, 'CARROTS' => 12, 'CASABA_MELON' => 13, 'CASHEWS' => 14,
        'CELERY' => 15, 'CHERRY_DARK_SWEET' => 16, 'CHERRY_MONTMORENCY' => 17, 'CRABAPPLES' => 18, 'CRANBERRY' => 19,
        'CURRANT_BLACK' => 20, 'CURRANT_RED' => 21, 'DATES' => 22, 'DATES_DRIED' => 23, 'DEWBERRY' => 24,
        'ELDERBERRY' => 25, 'FIGS' => 26, 'FIGS_DRIED' => 27, 'GOOSEBERRY' => 28, 'GRAPE_CONCORD' => 29,
        'GRAPES' => 30, 'GRAPEFRUIT' => 31, 'GUANABANA' => 32, 'GUAVAS' => 33, 'HONEYDEW_MELON' => 34,
        'JACKFRUIT' => 35, 'KIWIS' => 36, 'LEMON_JUICE' => 37, 'LIME_JUICE' => 38, 'LYCHEE_LITCHI' => 39,
        'LOGANBERRY' => 40, 'MANGOS' => 41, 'MAPLE_SYRUP' => 42, 'MAPLE_SAP' => 43, 'MULBERRY' => 44,
        'NECTARINES' => 45, 'ORANGE_JUICE' => 46, 'PAPAYA' => 47, 'PASSIONFRUIT' => 48, 'PEACHES' => 49,
        'PEARS' => 50, 'PERSIMMON' => 51, 'PINEAPPLES' => 52, 'PLUMS' => 53, 'POMEGRANATES' => 54,
        'PRICKLY_PEAR' => 55, 'PRUNES_DRIED' => 56, 'QUINCES' => 57, 'RAISINS_DRIED' => 58, 'RASPBERRY_BLACK' => 59,
        'RASPBERRY_RED' => 60, 'RHUBARB' => 61, 'STRAWBERRY' => 62, 'SULTANAS' => 63, 'TANGERINES' => 64,
        'TANGELO' => 65, 'TOMATOES' => 66, 'WATERMELONS' => 67, 'YOUNGBERRY' => 68, 'DME' => 69,
        'LME' => 70, 'APPLE_JUICE' => 71, 'CRANBERRY_JUICE' => 72,
    ];

    // sugar sources the calculator knows about, with estimated sugar content and YAN provided
    public const SUGAR_SOURCE_INFO = [
        ['name' => 'Honey', 'percent' => 79.6, 'yan' => 0],
        ['name' => 'Sugar', 'percent' => 100, 'yan' => 0],
        ['name' => 'Acerola', 'percent' => 3.33, 'yan' => 0.5],
        ['name' => 'Apple(s)', 'percent' => 12.4, 'yan' => 0.237],
        ['name' => 'Apricot(s)', 'percent' => 9.1, 'yan' => 0.5],
        ['name' => 'Apricots (dried)', 'percent' => 39.8, 'yan' => 0.5],
        ['name' => 'Banana(s)', 'percent' => 15.5, 'yan' => 0.5],
        ['name' => 'Blackberry', 'percent' => 8.0, 'yan' => 0.5],
        ['name' => 'Blueberry', 'percent' => 9.8, 'yan' => 0.5],
        ['name' => 'Boysenberry', 'percent' => 5.56, 'yan' => 0.5],
        ['name' => 'Cantaloupe', 'percent' => 7.6, 'yan' => 0.5],
        ['name' => 'Carambola', 'percent' => 4.33, 'yan' => 0.5],
        ['name' => 'Carrot(s)', 'percent' => 4.44, 'yan' => 0.5],
        ['name' => 'Casaba Melon', 'percent' => 4.17, 'yan' => 0.5],
        ['name' => 'Cashew(s)', 'percent' => 6.67, 'yan' => 0.5],
        ['name' => 'Celery', 'percent' => 1.72, 'yan' => 0.5],
        ['name' => 'Cherry, dark sweet', 'percent' => 14.2, 'yan' => 0.5],
        ['name' => 'Cherry, Montmorency', 'percent' => 7.9, 'yan' => 0.5],
        ['name' => 'Crabapple(s)', 'percent' => 8.56, 'yan' => 0.5],
        ['name' => 'Cranberry', 'percent' => 4.17, 'yan' => -1],
        ['name' => 'Currant, black', 'percent' => 9.3, 'yan' => 0.5],
        ['name' => 'Currant, red', 'percent' => 6.0, 'yan' => 0.5],
        ['name' => 'Dates', 'percent' => 10.28, 'yan' => 0.5],
        ['name' => 'Dates (dried)', 'percent' => 64.2, 'yan' => 0.5],
        ['name' => 'Dewberry', 'percent' => 5.56, 'yan' => 0.5],
        ['name' => 'Elderberry', 'percent' => 6.11, 'yan' => 0.5],
        ['name' => 'Fig(s)', 'percent' => 11.8, 'yan' => 0.5],
        ['name' => 'Figs (dried)', 'percent' => 66.5, 'yan' => 0.5],
        ['name' => 'Gooseberry', 'percent' => 11.0, 'yan' => 0.5],
        ['name' => 'Grape, concord', 'percent' => 8.89, 'yan' => 0.5],
        ['name' => 'Grape(s)', 'percent' => 15.9, 'yan' => 0.55],
        ['name' => 'Grapefruit', 'percent' => 6.1, 'yan' => 0.5],
        ['name' => 'Guanabana', 'percent' => 8.89, 'yan' => 0.5],
        ['name' => 'Guava(s)', 'percent' => 6.2, 'yan' => 0.5],
        ['name' => 'Honeydew Melon', 'percent' => 10, 'yan' => 0.5],
        ['name' => 'Jackfruit', 'percent' => 18.4, 'yan' => 0.5],
        ['name' => 'Kiwi(s)', 'percent' => 12.8, 'yan' => 0.5],
        ['name' => 'Lemon Juice', 'percent' => 2.2, 'yan' => 0.5],
        ['name' => 'Lime Juice', 'percent' => 1.0, 'yan' => 0.5],
        ['name' => 'Lychee (Litchi)', 'percent' => 17.0, 'yan' => 0.5],
        ['name' => 'Loganberry', 'percent' => 5.83, 'yan' => 0.5],
        ['name' => 'Mango(s)', 'percent' => 11.4, 'yan' => 0.5],
        ['name' => 'Maple Syrup', 'percent' => 66.2, 'yan' => 0],
        ['name' => 'Maple Sap', 'percent' => 2.0, 'yan' => 0],
        ['name' => 'Mulberry', 'percent' => 13.5, 'yan' => 0.5],
        ['name' => 'Nectarines', 'percent' => 7.5, 'yan' => 0.5],
        ['name' => 'Orange Juice', 'percent' => 10.7, 'yan' => 0.5],
        ['name' => 'Papaya', 'percent' => 6.39, 'yan' => 0.5],
        ['name' => 'Passionfruit', 'percent' => 11.1, 'yan' => 0.5],
        ['name' => 'Peach(es)', 'percent' => 8.8, 'yan' => 0.5],
        ['name' => 'Pear(s)', 'percent' => 10.1, 'yan' => 0.5],
        ['name' => 'Persimmon', 'percent' => 14.0, 'yan' => 0.5],
        ['name' => 'Pineapple(s)', 'percent' => 12.0, 'yan' => 0.5],
        ['name' => 'Plum(s)', 'percent' => 9.8, 'yan' => 0.5],
        ['name' => 'Pomegranate(s)', 'percent' => 11.6, 'yan' => 0.5],
        ['name' => 'Prickly Pear', 'percent' => 11, 'yan' => 0.5],
        ['name' => 'Prunes (dried)', 'percent' => 44.0, 'yan' => 0.5],
        ['name' => 'Quince(s)', 'percent' => 1.0, 'yan' => 0.5],
        ['name' => 'Raisins (dried)', 'percent' => 65, 'yan' => 0.5],
        ['name' => 'Raspberry, black', 'percent' => 6.17, 'yan' => 0.5],
        ['name' => 'Raspberry, red', 'percent' => 5.11, 'yan' => 0.5],
        ['name' => 'Rhubarb', 'percent' => 0.9, 'yan' => 0.5],
        ['name' => 'Strawberry', 'percent' => 6.6, 'yan' => 0.5],
        ['name' => 'Sultanas', 'percent' => 19.0, 'yan' => 0.5],
        ['name' => 'Tangerine(s)', 'percent' => 6.56, 'yan' => 0.5],
        ['name' => 'Tangelo', 'percent' => 7.4, 'yan' => 0.5],
        ['name' => 'Tomato(es)', 'percent' => 2.78, 'yan' => 0.5],
        ['name' => 'Watermelon(s)', 'percent' => 9.0, 'yan' => 0.5],
        ['name' => 'Youngberry', 'percent' => 5.56, 'yan' => 0.5],
        ['name' => 'Dry Malt Extract', 'percent' => 94, 'yan' => 0.5],
        ['name' => 'Liquid Malt Extract', 'percent' => 92, 'yan' => 0.5],
        ['name' => 'Apple Juice', 'percent' => 9.5, 'yan' => 0.237],
        ['name' => 'Cranberry Juice', 'percent' => 7.3, 'yan' => -1],
    ];

    // OGs / FGs for the dry-FG estimate interpolation table
    public const DRY_FG_OG_VALUES = [1.0, 1.04, 1.1, 1.144];
    public const DRY_FG_FG_VALUES = [1.0, 0.998, 0.995, 0.99];
}
