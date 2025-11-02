
/* OBFUSCATION */

    function obfuscate(str) {
        if (typeof str !== 'string') return str;
        // Replace special symbols with visually similar Unicode
        return str
            .replace(/\[/g, '\u298B') // [ → ⦋
            .replace(/\]/g, '\u298C') // ] → ⦌
            .replace(/</g, '\u2039')   // < → ‹
            .replace(/>/g, '\u203A')   // > → ›
            .replace(/\{/g, '\u2774') // { → ❴
            .replace(/\}/g, '\u2775') // } → ❵
            .replace(/&/g, '\uFF06')   // & → ＆
            .replace(/"/g, '\uFF02')  // " → ＂
            .replace(/'/g, '\uFF07');  // ' → ＇
    }

    function deobfuscate(str) {
        if (typeof str !== 'string') return str;
        // Restore special symbols from Unicode
        return str
            .replace(/\u298B/g, '[')
            .replace(/\u298C/g, ']')
            .replace(/\u2039/g, '<')
            .replace(/\u203A/g, '>')
            .replace(/\u2774/g, '{')
            .replace(/\u2775/g, '}')
            .replace(/\uFF06/g, '&')
            .replace(/\uFF02/g, '"')
            .replace(/\uFF07/g, "'");
    }

    function gzipCompress(str) {
        return btoa(String.fromCharCode.apply(null, pako.gzip(str)));
    }

    function gzipDecompress(str) {
        var binary = atob(str);
        var arr = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) arr[i] = binary.charCodeAt(i);
        return pako.ungzip(arr, { to: 'string' });
    }

    function base_decode(str) {
        return decodeURIComponent(escape(atob(str)));
    }

    function base_encode(str) {
        return btoa(unescape(encodeURIComponent(str)));
    }

    function multilang_fix_flag_filename($lang_code) {
		$flag_actual = $lang_code;
		if ($lang_code === 'en') $flag_actual = 'gb';
		if ($lang_code === 'zh') $flag_actual = 'cn';
		if ($lang_code === 'ja') $flag_actual = 'jp';
		if ($lang_code === 'ko') $flag_actual = 'kr';
		if ($lang_code === 'he') $flag_actual = 'il';
		if ($lang_code === 'uk') $flag_actual = 'ua';
		if ($lang_code === 'ar') $flag_actual = 'sa';
		if ($lang_code === 'sv') $flag_actual = 'se';
		if ($lang_code === 'da') $flag_actual = 'dk';
		if ($lang_code === 'cs') $flag_actual = 'cz';
		if ($lang_code === 'el') $flag_actual = 'gr';
		return $flag_actual;
	}