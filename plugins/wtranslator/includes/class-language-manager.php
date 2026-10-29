<?php
// phpcs:ignoreFile
/**
 * Language Manager
 * Handles language detection, switching, and display
 *
 * @package WTranslator
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName,Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.VariableComment.MissingVar,Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.InlineComment.InvalidEndChar,WordPress.PHP.YodaConditions.NotYoda,Generic.CodeAnalysis.UnusedFunctionParameter.Found

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTranslator_Language_Manager {
	/**
	 * Supported languages with native names
	 * Complete language list
	 */
	private $languages = array(
		'ab'          => array(
			'name'   => 'Abkhaz',
			'native' => 'Аҧсуа',
			'flag'   => '🇬🇪',
		),
		'ace'         => array(
			'name'   => 'Acehnese',
			'native' => 'Acèh',
			'flag'   => '🇮🇩',
		),
		'ach'         => array(
			'name'   => 'Acholi',
			'native' => 'Acoli',
			'flag'   => '🇺🇬',
		),
		'aa'          => array(
			'name'   => 'Afar',
			'native' => 'Qafar',
			'flag'   => '🇩🇯',
		),
		'af'          => array(
			'name'   => 'Afrikaans',
			'native' => 'Afrikaans',
			'flag'   => '🇿🇦',
		),
		'sq'          => array(
			'name'   => 'Albanian',
			'native' => 'Shqip',
			'flag'   => '🇦🇱',
		),
		'alz'         => array(
			'name'   => 'Alur',
			'native' => 'Alur',
			'flag'   => '🇺🇬',
		),
		'am'          => array(
			'name'   => 'Amharic',
			'native' => 'አማርኛ',
			'flag'   => '🇪🇹',
		),
		'ar'          => array(
			'name'   => 'Arabic',
			'native' => 'العربية',
			'flag'   => '🇸🇦',
		),
		'hy'          => array(
			'name'   => 'Armenian',
			'native' => 'Հայերեն',
			'flag'   => '🇦🇲',
		),
		'as'          => array(
			'name'   => 'Assamese',
			'native' => 'অসমীয়া',
			'flag'   => '🇮🇳',
		),
		'av'          => array(
			'name'   => 'Avar',
			'native' => 'Авар',
			'flag'   => '🇷🇺',
		),
		'awa'         => array(
			'name'   => 'Awadhi',
			'native' => 'अवधी',
			'flag'   => '🇮🇳',
		),
		'ay'          => array(
			'name'   => 'Aymara',
			'native' => 'Aymar aru',
			'flag'   => '🇧🇴',
		),
		'az'          => array(
			'name'   => 'Azerbaijani',
			'native' => 'Azərbaycan dili',
			'flag'   => '🇦🇿',
		),
		'ban'         => array(
			'name'   => 'Balinese',
			'native' => 'Basa Bali',
			'flag'   => '🇮🇩',
		),
		'bal'         => array(
			'name'   => 'Baluchi',
			'native' => 'بلوچی',
			'flag'   => '🇵🇰',
		),
		'bm'          => array(
			'name'   => 'Bambara',
			'native' => 'Bamanankan',
			'flag'   => '🇲🇱',
		),
		'bci'         => array(
			'name'   => 'Baoulé',
			'native' => 'Baoulé',
			'flag'   => '🇨🇮',
		),
		'ba'          => array(
			'name'   => 'Bashkir',
			'native' => 'Башҡортса',
			'flag'   => '🇷🇺',
		),
		'eu'          => array(
			'name'   => 'Basque',
			'native' => 'Euskara',
			'flag'   => '🇪🇸',
		),
		'btx'         => array(
			'name'   => 'Batak Karo',
			'native' => 'Batak Karo',
			'flag'   => '🇮🇩',
		),
		'bts'         => array(
			'name'   => 'Batak Simalungun',
			'native' => 'Batak Simalungun',
			'flag'   => '🇮🇩',
		),
		'bbc'         => array(
			'name'   => 'Batak Toba',
			'native' => 'Batak Toba',
			'flag'   => '🇮🇩',
		),
		'be'          => array(
			'name'   => 'Belarusian',
			'native' => 'Беларуская мова',
			'flag'   => '🇧🇾',
		),
		'bem'         => array(
			'name'   => 'Bemba',
			'native' => 'Ichibemba',
			'flag'   => '🇿🇲',
		),
		'bn'          => array(
			'name'   => 'Bengali',
			'native' => 'বাংলা',
			'flag'   => '🇧🇩',
		),
		'bew'         => array(
			'name'   => 'Betawi',
			'native' => 'Betawi',
			'flag'   => '🇮🇩',
		),
		'bho'         => array(
			'name'   => 'Bhojpuri',
			'native' => 'भोजपुरी',
			'flag'   => '🇮🇳',
		),
		'bik'         => array(
			'name'   => 'Bikol',
			'native' => 'Bikol',
			'flag'   => '🇵🇭',
		),
		'bs'          => array(
			'name'   => 'Bosnian',
			'native' => 'Bosanski',
			'flag'   => '🇧🇦',
		),
		'br'          => array(
			'name'   => 'Breton',
			'native' => 'Brezhoneg',
			'flag'   => '🇫🇷',
		),
		'bg'          => array(
			'name'   => 'Bulgarian',
			'native' => 'Български',
			'flag'   => '🇧🇬',
		),
		'bua'         => array(
			'name'   => 'Buryat',
			'native' => 'Буряад',
			'flag'   => '🇷🇺',
		),
		'yue'         => array(
			'name'   => 'Cantonese',
			'native' => '粵語',
			'flag'   => '🇭🇰',
		),
		'ca'          => array(
			'name'   => 'Catalan',
			'native' => 'Català',
			'flag'   => '🇪🇸',
		),
		'ceb'         => array(
			'name'   => 'Cebuano',
			'native' => 'Cebuano',
			'flag'   => '🇵🇭',
		),
		'ch'          => array(
			'name'   => 'Chamorro',
			'native' => 'Chamoru',
			'flag'   => '🇬🇺',
		),
		'ce'          => array(
			'name'   => 'Chechen',
			'native' => 'Нохчийн',
			'flag'   => '🇷🇺',
		),
		'ny'          => array(
			'name'   => 'Chichewa',
			'native' => 'Chichewa',
			'flag'   => '🇲🇼',
		),
		'zh-CN'       => array(
			'name'   => 'Chinese (Simplified)',
			'native' => '简体中文',
			'flag'   => '🇨🇳',
		),
		'zh-TW'       => array(
			'name'   => 'Chinese (Traditional)',
			'native' => '繁體中文',
			'flag'   => '🇹🇼',
		),
		'chk'         => array(
			'name'   => 'Chuukese',
			'native' => 'Chuukese',
			'flag'   => '🇫🇲',
		),
		'cv'          => array(
			'name'   => 'Chuvash',
			'native' => 'Чӑваш',
			'flag'   => '🇷🇺',
		),
		'co'          => array(
			'name'   => 'Corsican',
			'native' => 'Corsu',
			'flag'   => '🇫🇷',
		),
		'crh'         => array(
			'name'   => 'Crimean Tatar (Cyrillic)',
			'native' => 'Къырымтатарджа',
			'flag'   => '🇺🇦',
		),
		'crh-Latn'    => array(
			'name'   => 'Crimean Tatar (Latin)',
			'native' => 'Qırımtatarca',
			'flag'   => '🇺🇦',
		),
		'hr'          => array(
			'name'   => 'Croatian',
			'native' => 'Hrvatski',
			'flag'   => '🇭🇷',
		),
		'cs'          => array(
			'name'   => 'Czech',
			'native' => 'Čeština',
			'flag'   => '🇨🇿',
		),
		'da'          => array(
			'name'   => 'Danish',
			'native' => 'Dansk',
			'flag'   => '🇩🇰',
		),
		'fa-AF'       => array(
			'name'   => 'Dari',
			'native' => 'دری',
			'flag'   => '🇦🇫',
		),
		'dv'          => array(
			'name'   => 'Dhivehi',
			'native' => 'ދިވެހި',
			'flag'   => '🇲🇻',
		),
		'din'         => array(
			'name'   => 'Dinka',
			'native' => 'Thuɔŋjäŋ',
			'flag'   => '🇸🇸',
		),
		'doi'         => array(
			'name'   => 'Dogri',
			'native' => 'डोगरी',
			'flag'   => '🇮🇳',
		),
		'dov'         => array(
			'name'   => 'Dombe',
			'native' => 'Dombe',
			'flag'   => '🇿🇲',
		),
		'nl'          => array(
			'name'   => 'Dutch',
			'native' => 'Nederlands',
			'flag'   => '🇳🇱',
		),
		'dyu'         => array(
			'name'   => 'Dyula',
			'native' => 'Julakan',
			'flag'   => '🇧🇫',
		),
		'dz'          => array(
			'name'   => 'Dzongkha',
			'native' => 'རྫོང་ཁ',
			'flag'   => '🇧🇹',
		),
		'en'          => array(
			'name'   => 'English',
			'native' => 'English',
			'flag'   => '🇬🇧',
		),
		'eo'          => array(
			'name'   => 'Esperanto',
			'native' => 'Esperanto',
			'flag'   => '🌐',
		),
		'et'          => array(
			'name'   => 'Estonian',
			'native' => 'Eesti',
			'flag'   => '🇪🇪',
		),
		'ee'          => array(
			'name'   => 'Ewe',
			'native' => 'Eʋegbe',
			'flag'   => '🇬🇭',
		),
		'fo'          => array(
			'name'   => 'Faroese',
			'native' => 'Føroyskt',
			'flag'   => '🇫🇴',
		),
		'fj'          => array(
			'name'   => 'Fijian',
			'native' => 'Na Vosa Vakaviti',
			'flag'   => '🇫🇯',
		),
		'tl'          => array(
			'name'   => 'Filipino',
			'native' => 'Filipino',
			'flag'   => '🇵🇭',
		),
		'fi'          => array(
			'name'   => 'Finnish',
			'native' => 'Suomi',
			'flag'   => '🇫🇮',
		),
		'fon'         => array(
			'name'   => 'Fon',
			'native' => 'Fon gbè',
			'flag'   => '🇧🇯',
		),
		'fr'          => array(
			'name'   => 'French',
			'native' => 'Français',
			'flag'   => '🇫🇷',
		),
		'fr-CA'       => array(
			'name'   => 'French (Canada)',
			'native' => 'Français (Canada)',
			'flag'   => '🇨🇦',
		),
		'fy'          => array(
			'name'   => 'Frisian',
			'native' => 'Frysk',
			'flag'   => '🇳🇱',
		),
		'fur'         => array(
			'name'   => 'Friulian',
			'native' => 'Furlan',
			'flag'   => '🇮🇹',
		),
		'ff'          => array(
			'name'   => 'Fulani',
			'native' => 'Fulfulde',
			'flag'   => '🇸🇳',
		),
		'gaa'         => array(
			'name'   => 'Ga',
			'native' => 'Ga',
			'flag'   => '🇬🇭',
		),
		'gl'          => array(
			'name'   => 'Galician',
			'native' => 'Galego',
			'flag'   => '🇪🇸',
		),
		'ka'          => array(
			'name'   => 'Georgian',
			'native' => 'ქართული',
			'flag'   => '🇬🇪',
		),
		'de'          => array(
			'name'   => 'German',
			'native' => 'Deutsch',
			'flag'   => '🇩🇪',
		),
		'el'          => array(
			'name'   => 'Greek',
			'native' => 'Ελληνικά',
			'flag'   => '🇬🇷',
		),
		'gn'          => array(
			'name'   => 'Guarani',
			'native' => 'Avañe\'ẽ',
			'flag'   => '🇵🇾',
		),
		'gu'          => array(
			'name'   => 'Gujarati',
			'native' => 'ગુજરાતી',
			'flag'   => '🇮🇳',
		),
		'ht'          => array(
			'name'   => 'Haitian Creole',
			'native' => 'Kreyol ayisyen',
			'flag'   => '🇭🇹',
		),
		'cnh'         => array(
			'name'   => 'Hakha Chin',
			'native' => 'Laiholh',
			'flag'   => '🇲🇲',
		),
		'ha'          => array(
			'name'   => 'Hausa',
			'native' => 'Hausa',
			'flag'   => '🇳🇬',
		),
		'haw'         => array(
			'name'   => 'Hawaiian',
			'native' => 'ʻŌlelo Hawaiʻi',
			'flag'   => '🇺🇸',
		),
		'he'          => array(
			'name'   => 'Hebrew',
			'native' => 'עברית',
			'flag'   => '🇮🇱',
		),
		'hil'         => array(
			'name'   => 'Hiligaynon',
			'native' => 'Hiligaynon',
			'flag'   => '🇵🇭',
		),
		'hi'          => array(
			'name'   => 'Hindi',
			'native' => 'हिन्दी',
			'flag'   => '🇮🇳',
		),
		'hmn'         => array(
			'name'   => 'Hmong',
			'native' => 'Hmong',
			'flag'   => '🇨🇳',
		),
		'hu'          => array(
			'name'   => 'Hungarian',
			'native' => 'Magyar',
			'flag'   => '🇭🇺',
		),
		'hrx'         => array(
			'name'   => 'Hunsrik',
			'native' => 'Hunsrik',
			'flag'   => '🇧🇷',
		),
		'iba'         => array(
			'name'   => 'Iban',
			'native' => 'Iban',
			'flag'   => '🇲🇾',
		),
		'is'          => array(
			'name'   => 'Icelandic',
			'native' => 'Íslenska',
			'flag'   => '🇮🇸',
		),
		'ig'          => array(
			'name'   => 'Igbo',
			'native' => 'Igbo',
			'flag'   => '🇳🇬',
		),
		'ilo'         => array(
			'name'   => 'Ilocano',
			'native' => 'Ilokano',
			'flag'   => '🇵🇭',
		),
		'id'          => array(
			'name'   => 'Indonesian',
			'native' => 'Bahasa Indonesia',
			'flag'   => '🇮🇩',
		),
		'iu-Latn'     => array(
			'name'   => 'Inuktut (Latin)',
			'native' => 'Inuktut',
			'flag'   => '🇨🇦',
		),
		'iu'          => array(
			'name'   => 'Inuktut (Syllabics)',
			'native' => 'ᐃᓄᒃᑎᑐᑦ',
			'flag'   => '🇨🇦',
		),
		'ga'          => array(
			'name'   => 'Irish',
			'native' => 'Gaeilge',
			'flag'   => '🇮🇪',
		),
		'it'          => array(
			'name'   => 'Italian',
			'native' => 'Italiano',
			'flag'   => '🇮🇹',
		),
		'jam'         => array(
			'name'   => 'Jamaican Patois',
			'native' => 'Jamiekan',
			'flag'   => '🇯🇲',
		),
		'ja'          => array(
			'name'   => 'Japanese',
			'native' => '日本語',
			'flag'   => '🇯🇵',
		),
		'jw'          => array(
			'name'   => 'Javanese',
			'native' => 'Basa Jawa',
			'flag'   => '🇮🇩',
		),
		'kac'         => array(
			'name'   => 'Jingpo',
			'native' => 'Jingpho',
			'flag'   => '🇲🇲',
		),
		'kl'          => array(
			'name'   => 'Kalaallisut',
			'native' => 'Kalaallisut',
			'flag'   => '🇬🇱',
		),
		'kn'          => array(
			'name'   => 'Kannada',
			'native' => 'ಕನ್ನಡ',
			'flag'   => '🇮🇳',
		),
		'kr'          => array(
			'name'   => 'Kanuri',
			'native' => 'Kanuri',
			'flag'   => '🇳🇬',
		),
		'pam'         => array(
			'name'   => 'Kapampangan',
			'native' => 'Kapampangan',
			'flag'   => '🇵🇭',
		),
		'kk'          => array(
			'name'   => 'Kazakh',
			'native' => 'Қазақ тілі',
			'flag'   => '🇰🇿',
		),
		'kha'         => array(
			'name'   => 'Khasi',
			'native' => 'Khasi',
			'flag'   => '🇮🇳',
		),
		'km'          => array(
			'name'   => 'Khmer',
			'native' => 'ខ្មែរ',
			'flag'   => '🇰🇭',
		),
		'cgg'         => array(
			'name'   => 'Kiga',
			'native' => 'Rukiga',
			'flag'   => '🇺🇬',
		),
		'kg'          => array(
			'name'   => 'Kikongo',
			'native' => 'Kikongo',
			'flag'   => '🇨🇩',
		),
		'rw'          => array(
			'name'   => 'Kinyarwanda',
			'native' => 'Kinyarwanda',
			'flag'   => '🇷🇼',
		),
		'ktu'         => array(
			'name'   => 'Kituba',
			'native' => 'Kituba',
			'flag'   => '🇨🇩',
		),
		'trp'         => array(
			'name'   => 'Kokborok',
			'native' => 'Kokborok',
			'flag'   => '🇮🇳',
		),
		'kv'          => array(
			'name'   => 'Komi',
			'native' => 'Коми',
			'flag'   => '🇷🇺',
		),
		'gom'         => array(
			'name'   => 'Konkani',
			'native' => 'कोंकणी',
			'flag'   => '🇮🇳',
		),
		'ko'          => array(
			'name'   => 'Korean',
			'native' => '한국어',
			'flag'   => '🇰🇷',
		),
		'kri'         => array(
			'name'   => 'Krio',
			'native' => 'Krio',
			'flag'   => '🇸🇱',
		),
		'ku'          => array(
			'name'   => 'Kurdish (Kurmanji)',
			'native' => 'Kurdî',
			'flag'   => '🇮🇶',
		),
		'ckb'         => array(
			'name'   => 'Kurdish (Sorani)',
			'native' => 'کوردیی سۆرانی',
			'flag'   => '🇮🇶',
		),
		'ky'          => array(
			'name'   => 'Kyrgyz',
			'native' => 'Кыргызча',
			'flag'   => '🇰🇬',
		),
		'lo'          => array(
			'name'   => 'Lao',
			'native' => 'ລາວ',
			'flag'   => '🇱🇦',
		),
		'ltg'         => array(
			'name'   => 'Latgalian',
			'native' => 'Latgalīšu',
			'flag'   => '🇱🇻',
		),
		'la'          => array(
			'name'   => 'Latin',
			'native' => 'Latina',
			'flag'   => '🇻🇦',
		),
		'lv'          => array(
			'name'   => 'Latvian',
			'native' => 'Latviešu valoda',
			'flag'   => '🇱🇻',
		),
		'lij'         => array(
			'name'   => 'Ligurian',
			'native' => 'Ligure',
			'flag'   => '🇮🇹',
		),
		'li'          => array(
			'name'   => 'Limburgish',
			'native' => 'Limburgs',
			'flag'   => '🇳🇱',
		),
		'ln'          => array(
			'name'   => 'Lingala',
			'native' => 'Lingála',
			'flag'   => '🇨🇩',
		),
		'lt'          => array(
			'name'   => 'Lithuanian',
			'native' => 'Lietuvių kalba',
			'flag'   => '🇱🇹',
		),
		'lmo'         => array(
			'name'   => 'Lombard',
			'native' => 'Lombard',
			'flag'   => '🇮🇹',
		),
		'lg'          => array(
			'name'   => 'Luganda',
			'native' => 'Luganda',
			'flag'   => '🇺🇬',
		),
		'luo'         => array(
			'name'   => 'Luo',
			'native' => 'Dholuo',
			'flag'   => '🇰🇪',
		),
		'lb'          => array(
			'name'   => 'Luxembourgish',
			'native' => 'Lëtzebuergesch',
			'flag'   => '🇱🇺',
		),
		'mk'          => array(
			'name'   => 'Macedonian',
			'native' => 'Македонски јазик',
			'flag'   => '🇲🇰',
		),
		'mad'         => array(
			'name'   => 'Madurese',
			'native' => 'Madhura',
			'flag'   => '🇮🇩',
		),
		'mai'         => array(
			'name'   => 'Maithili',
			'native' => 'मैथिली',
			'flag'   => '🇮🇳',
		),
		'mak'         => array(
			'name'   => 'Makassar',
			'native' => 'Basa Mangkasara',
			'flag'   => '🇮🇩',
		),
		'mg'          => array(
			'name'   => 'Malagasy',
			'native' => 'Malagasy',
			'flag'   => '🇲🇬',
		),
		'ms'          => array(
			'name'   => 'Malay',
			'native' => 'Bahasa Melayu',
			'flag'   => '🇲🇾',
		),
		'ms-Arab'     => array(
			'name'   => 'Malay (Jawi)',
			'native' => 'بهاس ملايو',
			'flag'   => '🇲🇾',
		),
		'ml'          => array(
			'name'   => 'Malayalam',
			'native' => 'മലയാളം',
			'flag'   => '🇮🇳',
		),
		'mt'          => array(
			'name'   => 'Maltese',
			'native' => 'Malti',
			'flag'   => '🇲🇹',
		),
		'mam'         => array(
			'name'   => 'Mam',
			'native' => 'Mam',
			'flag'   => '🇬🇹',
		),
		'gv'          => array(
			'name'   => 'Manx',
			'native' => 'Gaelg',
			'flag'   => '🇮🇲',
		),
		'mi'          => array(
			'name'   => 'Maori',
			'native' => 'Te Reo Māori',
			'flag'   => '🇳🇿',
		),
		'mr'          => array(
			'name'   => 'Marathi',
			'native' => 'मराठी',
			'flag'   => '🇮🇳',
		),
		'mh'          => array(
			'name'   => 'Marshallese',
			'native' => 'Kajin M̧ajeļ',
			'flag'   => '🇲🇭',
		),
		'mwr'         => array(
			'name'   => 'Marwadi',
			'native' => 'मारवाड़ी',
			'flag'   => '🇮🇳',
		),
		'mfe'         => array(
			'name'   => 'Mauritian Creole',
			'native' => 'Morisien',
			'flag'   => '🇲🇺',
		),
		'chm'         => array(
			'name'   => 'Meadow Mari',
			'native' => 'Марий',
			'flag'   => '🇷🇺',
		),
		'mni-Mtei'    => array(
			'name'   => 'Meiteilon (Manipuri)',
			'native' => 'ꯃꯤꯇꯩꯂꯣꯟ',
			'flag'   => '🇮🇳',
		),
		'min'         => array(
			'name'   => 'Minang',
			'native' => 'Baso Minang',
			'flag'   => '🇮🇩',
		),
		'lus'         => array(
			'name'   => 'Mizo',
			'native' => 'Mizo ṭawng',
			'flag'   => '🇮🇳',
		),
		'mn'          => array(
			'name'   => 'Mongolian',
			'native' => 'Монгол',
			'flag'   => '🇲🇳',
		),
		'my'          => array(
			'name'   => 'Myanmar (Burmese)',
			'native' => 'မြန်မာ',
			'flag'   => '🇲🇲',
		),
		'nhe'         => array(
			'name'   => 'Nahuatl (Eastern Huasteca)',
			'native' => 'Nahuatl',
			'flag'   => '🇲🇽',
		),
		'ndc-ZW'      => array(
			'name'   => 'Ndau',
			'native' => 'Ndau',
			'flag'   => '🇿🇼',
		),
		'nr'          => array(
			'name'   => 'Ndebele (South)',
			'native' => 'isiNdebele',
			'flag'   => '🇿🇦',
		),
		'new'         => array(
			'name'   => 'Nepalbhasa (Newari)',
			'native' => 'नेपाल भाषा',
			'flag'   => '🇳🇵',
		),
		'ne'          => array(
			'name'   => 'Nepali',
			'native' => 'नेपाली',
			'flag'   => '🇳🇵',
		),
		'bm-Nkoo'     => array(
			'name'   => 'NKo',
			'native' => 'ߒߞߏ',
			'flag'   => '🇬🇳',
		),
		'no'          => array(
			'name'   => 'Norwegian',
			'native' => 'Norsk',
			'flag'   => '🇳🇴',
		),
		'nus'         => array(
			'name'   => 'Nuer',
			'native' => 'Thok Nath',
			'flag'   => '🇸🇸',
		),
		'oc'          => array(
			'name'   => 'Occitan',
			'native' => 'Occitan',
			'flag'   => '🇫🇷',
		),
		'or'          => array(
			'name'   => 'Odia (Oriya)',
			'native' => 'ଓଡ଼ିଆ',
			'flag'   => '🇮🇳',
		),
		'om'          => array(
			'name'   => 'Oromo',
			'native' => 'Afaan Oromoo',
			'flag'   => '🇪🇹',
		),
		'os'          => array(
			'name'   => 'Ossetian',
			'native' => 'Ирон',
			'flag'   => '🇬🇪',
		),
		'pag'         => array(
			'name'   => 'Pangasinan',
			'native' => 'Pangasinan',
			'flag'   => '🇵🇭',
		),
		'pap'         => array(
			'name'   => 'Papiamento',
			'native' => 'Papiamentu',
			'flag'   => '🇨🇼',
		),
		'ps'          => array(
			'name'   => 'Pashto',
			'native' => 'پښتو',
			'flag'   => '🇦🇫',
		),
		'fa'          => array(
			'name'   => 'Persian',
			'native' => 'فارسی',
			'flag'   => '🇮🇷',
		),
		'pl'          => array(
			'name'   => 'Polish',
			'native' => 'Polski',
			'flag'   => '🇵🇱',
		),
		'pt'          => array(
			'name'   => 'Portuguese (Brazil)',
			'native' => 'Português (Brasil)',
			'flag'   => '🇧🇷',
		),
		'pt-PT'       => array(
			'name'   => 'Portuguese (Portugal)',
			'native' => 'Português (Portugal)',
			'flag'   => '🇵🇹',
		),
		'pa'          => array(
			'name'   => 'Punjabi',
			'native' => 'ਪੰਜਾਬੀ',
			'flag'   => '🇮🇳',
		),
		'pa-Arab'     => array(
			'name'   => 'Punjabi (Shahmukhi)',
			'native' => 'پنجابی',
			'flag'   => '🇵🇰',
		),
		'qu'          => array(
			'name'   => 'Quechua',
			'native' => 'Runasimi',
			'flag'   => '🇵🇪',
		),
		'kek'         => array(
			'name'   => 'Qʼeqchiʼ',
			'native' => 'Q\'eqchi\'',
			'flag'   => '🇬🇹',
		),
		'rom'         => array(
			'name'   => 'Romani',
			'native' => 'Romani',
			'flag'   => '🌐',
		),
		'ro'          => array(
			'name'   => 'Romanian',
			'native' => 'Română',
			'flag'   => '🇷🇴',
		),
		'rn'          => array(
			'name'   => 'Rundi',
			'native' => 'Ikirundi',
			'flag'   => '🇧🇮',
		),
		'ru'          => array(
			'name'   => 'Russian',
			'native' => 'Русский',
			'flag'   => '🇷🇺',
		),
		'se'          => array(
			'name'   => 'Sami (North)',
			'native' => 'Davvisámegiella',
			'flag'   => '🇳🇴',
		),
		'sm'          => array(
			'name'   => 'Samoan',
			'native' => 'Samoan',
			'flag'   => '🇼🇸',
		),
		'sg'          => array(
			'name'   => 'Sango',
			'native' => 'Sängö',
			'flag'   => '🇨🇫',
		),
		'sa'          => array(
			'name'   => 'Sanskrit',
			'native' => 'संस्कृतम्',
			'flag'   => '🇮🇳',
		),
		'sat-Latn'    => array(
			'name'   => 'Santali (Latin)',
			'native' => 'Santali',
			'flag'   => '🇮🇳',
		),
		'sat'         => array(
			'name'   => 'Santali (Ol Chiki)',
			'native' => 'ᱥᱟᱱᱛᱟᱲᱤ',
			'flag'   => '🇮🇳',
		),
		'gd'          => array(
			'name'   => 'Scottish Gaelic',
			'native' => 'Gàidhlig',
			'flag'   => '🇬🇧',
		),
		'nso'         => array(
			'name'   => 'Sepedi',
			'native' => 'Sepedi',
			'flag'   => '🇿🇦',
		),
		'sr'          => array(
			'name'   => 'Serbian',
			'native' => 'Српски језик',
			'flag'   => '🇷🇸',
		),
		'st'          => array(
			'name'   => 'Sesotho',
			'native' => 'Sesotho',
			'flag'   => '🇱🇸',
		),
		'crs'         => array(
			'name'   => 'Seychellois Creole',
			'native' => 'Kreol Seselwa',
			'flag'   => '🇸🇨',
		),
		'shn'         => array(
			'name'   => 'Shan',
			'native' => 'လိၵ်ႈတႆး',
			'flag'   => '🇲🇲',
		),
		'sn'          => array(
			'name'   => 'Shona',
			'native' => 'Shona',
			'flag'   => '🇿🇼',
		),
		'scn'         => array(
			'name'   => 'Sicilian',
			'native' => 'Sicilianu',
			'flag'   => '🇮🇹',
		),
		'szl'         => array(
			'name'   => 'Silesian',
			'native' => 'Ślůnsko godka',
			'flag'   => '🇵🇱',
		),
		'sd'          => array(
			'name'   => 'Sindhi',
			'native' => 'سنڌي',
			'flag'   => '🇵🇰',
		),
		'si'          => array(
			'name'   => 'Sinhala',
			'native' => 'සිංහල',
			'flag'   => '🇱🇰',
		),
		'sk'          => array(
			'name'   => 'Slovak',
			'native' => 'Slovenčina',
			'flag'   => '🇸🇰',
		),
		'sl'          => array(
			'name'   => 'Slovenian',
			'native' => 'Slovenščina',
			'flag'   => '🇸🇮',
		),
		'so'          => array(
			'name'   => 'Somali',
			'native' => 'Soomaali',
			'flag'   => '🇸🇴',
		),
		'es'          => array(
			'name'   => 'Spanish',
			'native' => 'Español',
			'flag'   => '🇪🇸',
		),
		'su'          => array(
			'name'   => 'Sundanese',
			'native' => 'Basa Sunda',
			'flag'   => '🇮🇩',
		),
		'sus'         => array(
			'name'   => 'Susu',
			'native' => 'Sosoxui',
			'flag'   => '🇬🇳',
		),
		'sw'          => array(
			'name'   => 'Swahili',
			'native' => 'Kiswahili',
			'flag'   => '🇹🇿',
		),
		'ss'          => array(
			'name'   => 'Swati',
			'native' => 'SiSwati',
			'flag'   => '🇸🇿',
		),
		'sv'          => array(
			'name'   => 'Swedish',
			'native' => 'Svenska',
			'flag'   => '🇸🇪',
		),
		'ty'          => array(
			'name'   => 'Tahitian',
			'native' => 'Reo Tahiti',
			'flag'   => '🇵🇫',
		),
		'tg'          => array(
			'name'   => 'Tajik',
			'native' => 'Тоҷикӣ',
			'flag'   => '🇹🇯',
		),
		'ber-Latn'    => array(
			'name'   => 'Tamazight',
			'native' => 'Tamaziɣt',
			'flag'   => '🇲🇦',
		),
		'ber'         => array(
			'name'   => 'Tamazight (Tifinagh)',
			'native' => 'ⵜⴰⵎⴰⵣⵉⵖⵜ',
			'flag'   => '🇲🇦',
		),
		'ta'          => array(
			'name'   => 'Tamil',
			'native' => 'தமிழ்',
			'flag'   => '🇮🇳',
		),
		'tt'          => array(
			'name'   => 'Tatar',
			'native' => 'Татарча',
			'flag'   => '🇷🇺',
		),
		'te'          => array(
			'name'   => 'Telugu',
			'native' => 'తెలుగు',
			'flag'   => '🇮🇳',
		),
		'tet'         => array(
			'name'   => 'Tetum',
			'native' => 'Tetun',
			'flag'   => '🇹🇱',
		),
		'th'          => array(
			'name'   => 'Thai',
			'native' => 'ไทย',
			'flag'   => '🇹🇭',
		),
		'bo'          => array(
			'name'   => 'Tibetan',
			'native' => 'བོད་སྐད་',
			'flag'   => '🇨🇳',
		),
		'ti'          => array(
			'name'   => 'Tigrinya',
			'native' => 'ትግርኛ',
			'flag'   => '🇪🇷',
		),
		'tiv'         => array(
			'name'   => 'Tiv',
			'native' => 'Tiv',
			'flag'   => '🇳🇬',
		),
		'tpi'         => array(
			'name'   => 'Tok Pisin',
			'native' => 'Tok Pisin',
			'flag'   => '🇵🇬',
		),
		'to'          => array(
			'name'   => 'Tongan',
			'native' => 'Lea faka-Tonga',
			'flag'   => '🇹🇴',
		),
		'lua'         => array(
			'name'   => 'Tshiluba',
			'native' => 'Tshiluba',
			'flag'   => '🇨🇩',
		),
		'ts'          => array(
			'name'   => 'Tsonga',
			'native' => 'Xitsonga',
			'flag'   => '🇿🇦',
		),
		'tn'          => array(
			'name'   => 'Tswana',
			'native' => 'Setswana',
			'flag'   => '🇧🇼',
		),
		'tcy'         => array(
			'name'   => 'Tulu',
			'native' => 'ತುಳು',
			'flag'   => '🇮🇳',
		),
		'tum'         => array(
			'name'   => 'Tumbuka',
			'native' => 'ChiTumbuka',
			'flag'   => '🇲🇼',
		),
		'tr'          => array(
			'name'   => 'Turkish',
			'native' => 'Türkçe',
			'flag'   => '🇹🇷',
		),
		'tk'          => array(
			'name'   => 'Turkmen',
			'native' => 'Türkmençe',
			'flag'   => '🇹🇲',
		),
		'tyv'         => array(
			'name'   => 'Tuvan',
			'native' => 'Тыва дыл',
			'flag'   => '🇷🇺',
		),
		'ak'          => array(
			'name'   => 'Twi',
			'native' => 'Twi',
			'flag'   => '🇬🇭',
		),
		'udm'         => array(
			'name'   => 'Udmurt',
			'native' => 'Удмурт',
			'flag'   => '🇷🇺',
		),
		'uk'          => array(
			'name'   => 'Ukrainian',
			'native' => 'Українська',
			'flag'   => '🇺🇦',
		),
		'ur'          => array(
			'name'   => 'Urdu',
			'native' => 'اردو',
			'flag'   => '🇵🇰',
		),
		'ug'          => array(
			'name'   => 'Uyghur',
			'native' => 'ئۇيغۇرچە',
			'flag'   => '🇨🇳',
		),
		'uz'          => array(
			'name'   => 'Uzbek',
			'native' => 'Oʻzbekcha',
			'flag'   => '🇺🇿',
		),
		've'          => array(
			'name'   => 'Venda',
			'native' => 'Tshivenḓa',
			'flag'   => '🇿🇦',
		),
		'vec'         => array(
			'name'   => 'Venetian',
			'native' => 'Vèneto',
			'flag'   => '🇮🇹',
		),
		'vi'          => array(
			'name'   => 'Vietnamese',
			'native' => 'Tiếng Việt',
			'flag'   => '🇻🇳',
		),
		'war'         => array(
			'name'   => 'Waray',
			'native' => 'Winaray',
			'flag'   => '🇵🇭',
		),
		'cy'          => array(
			'name'   => 'Welsh',
			'native' => 'Cymraeg',
			'flag'   => '🇬🇧',
		),
		'wo'          => array(
			'name'   => 'Wolof',
			'native' => 'Wolof',
			'flag'   => '🇸🇳',
		),
		'xh'          => array(
			'name'   => 'Xhosa',
			'native' => 'isiXhosa',
			'flag'   => '🇿🇦',
		),
		'sah'         => array(
			'name'   => 'Yakut',
			'native' => 'Саха тыла',
			'flag'   => '🇷🇺',
		),
		'yi'          => array(
			'name'   => 'Yiddish',
			'native' => 'ייִדיש',
			'flag'   => '🇮🇱',
		),
		'yo'          => array(
			'name'   => 'Yoruba',
			'native' => 'Yorùbá',
			'flag'   => '🇳🇬',
		),
		'yua'         => array(
			'name'   => 'Yucatec Maya',
			'native' => 'Maaya t\'aan',
			'flag'   => '🇲🇽',
		),
		'zap'         => array(
			'name'   => 'Zapotec',
			'native' => 'Diidxazá',
			'flag'   => '🇲🇽',
		),
		'zu'          => array(
			'name'   => 'Zulu',
			'native' => 'Zulu',
			'flag'   => '🇿🇦',
		),
	);

	/**
	 * Get all supported languages
	 */
	public function get_all_languages() {
		return $this->languages;
	}

	/**
	 * Get enabled languages from settings
	 */
	public function get_enabled_languages() {
		$settings = get_option( 'wtranslator_settings', array() );
		$enabled  = ! empty( $settings['enabled_languages'] ) ? $settings['enabled_languages'] : array( 'en' );

		$result = array();
		foreach ( $enabled as $code ) {
			if ( isset( $this->languages[ $code ] ) ) {
				$result[ $code ] = $this->languages[ $code ];
			}
		}

		return $result;
	}

	/**
	 * Check if a language is enabled
	 */
	public function is_enabled_language( $code ) {
		$enabled = $this->get_enabled_languages();
		return isset( $enabled[ $code ] );
	}

	/**
	 * Check if language code is valid
	 */
	public function is_valid_language( $code ) {
		return (bool) $this->match_language_code( $code, $this->languages );
	}

	/**
	 * Map a cookie/URL/Google code onto a known plugin language key.
	 *
	 * @param string               $code      Raw language code.
	 * @param array<string, mixed> $languages Optional list to match against.
	 * @return string Empty string when no match.
	 */
	public function match_language_code( $code, $languages = null ) {
		if ( $languages === null ) {
			$languages = $this->languages;
		}
		if ( ! is_array( $languages ) || $code === '' || $code === null ) {
			return '';
		}

		$code = (string) $code;
		if ( isset( $languages[ $code ] ) ) {
			return $code;
		}

		foreach ( array_keys( $languages ) as $known ) {
			if ( strcasecmp( (string) $known, $code ) === 0 ) {
				return (string) $known;
			}
		}

		$plugin = class_exists( 'WTranslator' ) ? WTranslator::get_instance() : null;
		if ( $plugin && method_exists( $plugin, 'normalize_language_code' ) ) {
			$normalized = $plugin->normalize_language_code( $code );
			if ( $normalized !== '' && isset( $languages[ $normalized ] ) ) {
				return $normalized;
			}
			foreach ( array_keys( $languages ) as $known ) {
				if ( strcasecmp( (string) $known, $normalized ) === 0 ) {
					return (string) $known;
				}
			}
		}

		return '';
	}

	/**
	 * Language to highlight on the switcher. Never fall back to an unrelated first item.
	 *
	 * @param array<string, mixed> $languages    Enabled languages.
	 * @param string               $current_lang Detected current language.
	 * @return string
	 */
	public function resolve_switcher_language( $languages, $current_lang ) {
		if ( ! is_array( $languages ) || $languages === array() ) {
			return (string) $current_lang;
		}

		$matched = $this->match_language_code( $current_lang, $languages );
		if ( $matched !== '' ) {
			return $matched;
		}

		$plugin  = class_exists( 'WTranslator' ) ? WTranslator::get_instance() : null;
		$default = $plugin ? $plugin->get_default_language() : 'en';
		$matched = $this->match_language_code( $default, $languages );
		if ( $matched !== '' ) {
			return $matched;
		}

		$keys = array_keys( $languages );
		return (string) $keys[0];
	}

	/**
	 * Get language name
	 */
	public function get_language_name( $code ) {
		return isset( $this->languages[ $code ] ) ? $this->languages[ $code ]['name'] : $code;
	}

	/**
	 * Get native language name
	 */
	public function get_native_name( $code ) {
		return isset( $this->languages[ $code ] ) ? $this->languages[ $code ]['native'] : $code;
	}

	/**
	 * Get language flag
	 */
	public function get_language_flag( $code ) {
		return isset( $this->languages[ $code ] ) ? $this->languages[ $code ]['flag'] : '🌐';
	}

	/**
	 * Render language switcher
	 */
	public function render_switcher( $settings = null ) {
		if ( $settings === null ) {
			$settings = get_option( 'wtranslator_settings', array() );
		}
		$enabled_languages = $this->get_enabled_languages();
		$current_lang      = $this->resolve_switcher_language( $enabled_languages, $this->get_current_language() );

		// If current language is valid but not in enabled list, inject it (Ad-hoc support)
		if ( ! isset( $enabled_languages[ $current_lang ] ) && $this->is_valid_language( $current_lang ) ) {
			$all_langs                          = $this->get_all_languages();
			$enabled_languages[ $current_lang ] = $all_langs[ $current_lang ];
		}

		// Use widget_look from settings, fallback to switcher_style for backward compatibility
		$widget_look = ! empty( $settings['widget_look'] ) ? $settings['widget_look'] : ( ! empty( $settings['switcher_style'] ) ? $settings['switcher_style'] : 'dropdown' );

		// Get position - convert underscore to hyphen if needed
		$switcher_position = ! empty( $settings['switcher_position'] ) ? $settings['switcher_position'] : 'bottom-right';
		if ( strpos( $switcher_position, '_' ) !== false ) {
			$switcher_position = str_replace( '_', '-', $switcher_position );
		}

		$open_direction        = ! empty( $settings['open_direction'] ) ? $settings['open_direction'] : 'up';
		$native_language_names = ! empty( $settings['native_language_names'] );

		// Check if we have at least 1 language enabled
		if ( empty( $enabled_languages ) ) {
			// If no languages, try to use default
			$enabled_languages = array( 'en' );
		}

		// If only 1 language, still show widget (user might want to add more later)
		// Minimum requirement: at least 1 language

		$position_class = $this->get_position_class( $switcher_position );
		$is_inline      = ( $switcher_position === 'inline' || empty( $position_class ) || $position_class === '' );

		?>
		<div id="wtranslator-switcher" class="wtranslator-switcher notranslate wtranslator-<?php echo esc_attr( $widget_look ); ?> <?php echo $is_inline ? '' : esc_attr( $position_class ); ?>" <?php echo $is_inline ? 'style="position:relative!important;z-index:auto!important;"' : ''; ?>>
			<?php if ( $widget_look === 'dropdown' || $widget_look === 'float' ) : ?>
				<?php $this->render_dropdown( $enabled_languages, $current_lang, $open_direction, $native_language_names ); ?>
			<?php elseif ( $widget_look === 'flags' ) : ?>
				<?php $this->render_flags( $enabled_languages, $current_lang, $native_language_names ); ?>
			<?php elseif ( $widget_look === 'flags_name' ) : ?>
				<?php $this->render_flags_name( $enabled_languages, $current_lang, $native_language_names ); ?>
			<?php else : ?>
				<?php $this->render_dropdown( $enabled_languages, $current_lang, $open_direction, $native_language_names ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render dropdown switcher
	 */
	private function render_dropdown( $languages, $current_lang, $open_direction = 'up', $native_names = false ) {
		$current_lang      = $this->resolve_switcher_language( $languages, $current_lang );
		$current_lang_info = $languages[ $current_lang ];
		?>
		<div class="wtranslator-dropdown notranslate <?php echo esc_attr( $open_direction === 'down' ? 'open-down' : 'open-up' ); ?>">
			<button class="wtranslator-dropdown-toggle notranslate" aria-label="<?php esc_attr_e( 'Select Language', 'wtranslator' ); ?>" aria-expanded="false">
				<span class="wtranslator-flag"><?php echo esc_html( $current_lang_info['flag'] ); ?></span>
				<span class="wtranslator-lang-name notranslate"><?php echo esc_html( $native_names ? $current_lang_info['native'] : $current_lang_info['name'] ); ?></span>
				<span class="wtranslator-arrow">▼</span>
			</button>
			<ul class="wtranslator-dropdown-menu notranslate" role="menu">
				<?php foreach ( $languages as $code => $lang ) : ?>
					<li role="none" class="notranslate">
						<a href="<?php echo esc_url( $this->get_language_url( $code ) ); ?>" 
							class="wtranslator-lang-item notranslate <?php echo $code === $current_lang ? 'active' : ''; ?>"
							data-lang="<?php echo esc_attr( $code ); ?>"
							role="menuitem">
							<span class="wtranslator-flag"><?php echo esc_html( $lang['flag'] ); ?></span>
							<span class="wtranslator-lang-name notranslate"><?php echo esc_html( $native_names ? $lang['native'] : $lang['name'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render flags switcher
	 */
	private function render_flags( $languages, $current_lang, $native_names = false ) {
		?>
		<div class="wtranslator-flags notranslate">
			<?php foreach ( $languages as $code => $lang ) : ?>
				<a href="<?php echo esc_url( $this->get_language_url( $code ) ); ?>" 
					class="wtranslator-flag-item notranslate <?php echo $code === $current_lang ? 'active' : ''; ?>"
					data-lang="<?php echo esc_attr( $code ); ?>"
					title="<?php echo esc_attr( $native_names ? $lang['native'] : $lang['name'] ); ?>">
					<?php echo esc_html( $lang['flag'] ); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render flags with name switcher
	 */
	private function render_flags_name( $languages, $current_lang, $native_names = false ) {
		?>
		<div class="wtranslator-flags-name notranslate">
			<?php foreach ( $languages as $code => $lang ) : ?>
				<a href="<?php echo esc_url( $this->get_language_url( $code ) ); ?>" 
					class="wtranslator-flag-name-item notranslate <?php echo $code === $current_lang ? 'active' : ''; ?>"
					data-lang="<?php echo esc_attr( $code ); ?>">
					<span class="wtranslator-flag"><?php echo esc_html( $lang['flag'] ); ?></span>
					<span class="wtranslator-lang-name notranslate"><?php echo esc_html( $native_names ? $lang['native'] : $lang['name'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Get language URL
	 */
	public function get_language_url( $lang_code ) {
		$settings     = get_option( 'wtranslator_settings', array() );
		$default_lang = ! empty( $settings['translate_from'] ) ? $settings['translate_from'] : 'en';

		$http_host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		$current_url = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http' ) . '://' . $http_host . $request_uri;
		$url_parts   = wp_parse_url( $current_url );

		// Always return a clean URL (no ?lang=) to prevent SEO/indexing via language query URLs.
		// Remove existing lang parameter.
		if ( isset( $url_parts['query'] ) ) {
			parse_str( $url_parts['query'], $query_params );
			unset( $query_params['lang'] );
			$url_parts['query'] = http_build_query( $query_params );
		}

		$new_url = $url_parts['scheme'] . '://' . $url_parts['host'];
		if ( isset( $url_parts['port'] ) ) {
			$new_url .= ':' . $url_parts['port'];
		}
		if ( isset( $url_parts['path'] ) ) {
			$new_url .= $url_parts['path'];
		}
		if ( isset( $url_parts['query'] ) ) {
			$new_url .= '?' . $url_parts['query'];
		}

		return $new_url;
	}

	/**
	 * Get position CSS class
	 */
	private function get_position_class( $position ) {
		$classes = array(
			'top-left'     => 'wtranslator-top-left',
			'top-right'    => 'wtranslator-top-right',
			'bottom-left'  => 'wtranslator-bottom-left',
			'bottom-right' => 'wtranslator-bottom-right',
		);

		return isset( $classes[ $position ] ) ? $classes[ $position ] : $classes['bottom-right'];
	}

	/**
	 * Get current language
	 */
	public function get_current_language() {
		$plugin = WTranslator::get_instance();
		return $plugin->get_current_language();
	}
}

