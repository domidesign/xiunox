function hexToHsl(hex) {
    hex = hex.replace('#', '');
    var r = parseInt(hex.substring(0, 2), 16) / 255;
    var g = parseInt(hex.substring(2, 4), 16) / 255;
    var b = parseInt(hex.substring(4, 6), 16) / 255;
    var max = Math.max(r, g, b);
    var min = Math.min(r, g, b);
    var h = 0, s = 0, l = (max + min) / 2;
    if (max !== min) {
        var d = max - min;
        s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
        if (max === r) h = ((g - b) / d + (g < b ? 6 : 0)) / 6;
        else if (max === g) h = ((b - r) / d + 2) / 6;
        else h = ((r - g) / d + 4) / 6;
    }
    return { h: h * 360, s: s * 100, l: l * 100 };
}

function hslToHex(h, s, l) {
    h = ((h % 360) + 360) % 360;
    s = Math.max(0, Math.min(100, s)) / 100;
    l = Math.max(0, Math.min(100, l)) / 100;
    var c = (1 - Math.abs(2 * l - 1)) * s;
    var x = c * (1 - Math.abs((h / 60) % 2 - 1));
    var m = l - c / 2;
    var r = 0, g = 0, b = 0;
    if (h < 60) { r = c; g = x; }
    else if (h < 120) { r = x; g = c; }
    else if (h < 180) { g = c; b = x; }
    else if (h < 240) { g = x; b = c; }
    else if (h < 300) { r = x; b = c; }
    else { r = c; b = x; }
    r = Math.round((r + m) * 255);
    g = Math.round((g + m) * 255);
    b = Math.round((b + m) * 255);
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
}

function hexToRgb(hex) {
    hex = hex.replace('#', '');
    return {
        r: parseInt(hex.substring(0, 2), 16),
        g: parseInt(hex.substring(2, 4), 16),
        b: parseInt(hex.substring(4, 6), 16)
    };
}

function getRelativeLuminance(hex) {
    var rgb = hexToRgb(hex);
    var r = rgb.r / 255, g = rgb.g / 255, b = rgb.b / 255;
    r = r <= 0.03928 ? r / 12.92 : Math.pow((r + 0.055) / 1.055, 2.4);
    g = g <= 0.03928 ? g / 12.92 : Math.pow((g + 0.055) / 1.055, 2.4);
    b = b <= 0.03928 ? b / 12.92 : Math.pow((b + 0.055) / 1.055, 2.4);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function generateColorScale(hex) {
    var hsl = hexToHsl(hex);
    var h = hsl.h;
    var s = hsl.s;
    var scale = {};
    var lightnessMap = {
        50: 96, 100: 92, 200: 84, 300: 72, 400: 60,
        500: 50, 600: 42, 700: 34, 800: 26, 900: 18, 950: 10
    };
    var saturationMap = {
        50: Math.max(s * 0.3, 10), 100: Math.max(s * 0.5, 20),
        200: Math.max(s * 0.7, 30), 300: Math.max(s * 0.85, 40),
        400: Math.max(s * 0.95, 50), 500: s,
        600: s, 700: Math.max(s, 50),
        800: Math.max(s * 0.9, 40), 900: Math.max(s * 0.8, 30),
        950: Math.max(s * 0.7, 20)
    };
    for (var level in lightnessMap) {
        var targetL = lightnessMap[level];
        var targetS = saturationMap[level];
        if (parseInt(level) === 500) {
            scale[level] = hex.toLowerCase();
        } else {
            scale[level] = hslToHex(h, targetS, targetL);
        }
    }
    return scale;
}

var _themeColorVars = {
    primary: ['--bs-primary', '--bs-primary-rgb',
        '--bs-primary-50', '--bs-primary-100', '--bs-primary-200', '--bs-primary-300',
        '--bs-primary-400', '--bs-primary-500', '--bs-primary-600', '--bs-primary-700',
        '--bs-primary-800', '--bs-primary-900', '--bs-primary-950',
        '--bs-link-color', '--bs-link-hover-color',
        '--bs-btn-primary-bg', '--bs-btn-primary-border-color',
        '--bs-btn-primary-hover-bg', '--bs-btn-primary-hover-border-color',
        '--bs-btn-primary-active-bg', '--bs-btn-primary-active-border-color',
        '--bs-primary-text-emphasis', '--bs-primary-bg-subtle', '--bs-primary-border-subtle',
        '--bs-focus-ring-color',
        '--bs-form-check-bg-checked', '--bs-form-check-border-color-checked',
        '--bs-nav-pills-link-active-bg', '--bs-nav-pills-link-active-color',
        '--bs-pagination-active-bg', '--bs-pagination-active-border-color',
        '--bs-progress-bar-bg', '--bs-list-group-active-bg', '--bs-list-group-active-border-color',
        '--bs-dropdown-link-active-bg', '--bs-dropdown-link-active-color',
        '--bs-badge-bg', '--bs-badge-color', '--bs-link-decoration', '--bs-link-hover-decoration'],
    success: ['--bs-success', '--bs-success-rgb',
        '--bs-success-50', '--bs-success-100', '--bs-success-200', '--bs-success-300',
        '--bs-success-400', '--bs-success-500', '--bs-success-600', '--bs-success-700',
        '--bs-success-800', '--bs-success-900', '--bs-success-950',
        '--bs-btn-success-bg', '--bs-btn-success-border-color',
        '--bs-btn-success-hover-bg', '--bs-btn-success-hover-border-color',
        '--bs-btn-success-active-bg', '--bs-btn-success-active-border-color',
        '--bs-success-text-emphasis', '--bs-success-bg-subtle', '--bs-success-border-subtle'],
    warning: ['--bs-warning', '--bs-warning-rgb',
        '--bs-warning-50', '--bs-warning-100', '--bs-warning-200', '--bs-warning-300',
        '--bs-warning-400', '--bs-warning-500', '--bs-warning-600', '--bs-warning-700',
        '--bs-warning-800', '--bs-warning-900', '--bs-warning-950',
        '--bs-btn-warning-bg', '--bs-btn-warning-border-color',
        '--bs-btn-warning-hover-bg', '--bs-btn-warning-hover-border-color',
        '--bs-btn-warning-active-bg', '--bs-btn-warning-active-border-color',
        '--bs-warning-text-emphasis', '--bs-warning-bg-subtle', '--bs-warning-border-subtle'],
    danger: ['--bs-danger', '--bs-danger-rgb',
        '--bs-danger-50', '--bs-danger-100', '--bs-danger-200', '--bs-danger-300',
        '--bs-danger-400', '--bs-danger-500', '--bs-danger-600', '--bs-danger-700',
        '--bs-danger-800', '--bs-danger-900', '--bs-danger-950',
        '--bs-btn-danger-bg', '--bs-btn-danger-border-color',
        '--bs-btn-danger-hover-bg', '--bs-btn-danger-hover-border-color',
        '--bs-btn-danger-active-bg', '--bs-btn-danger-active-border-color',
        '--bs-danger-text-emphasis', '--bs-danger-bg-subtle', '--bs-danger-border-subtle'],
    info: ['--bs-info', '--bs-info-rgb',
        '--bs-info-50', '--bs-info-100', '--bs-info-200', '--bs-info-300',
        '--bs-info-400', '--bs-info-500', '--bs-info-600', '--bs-info-700',
        '--bs-info-800', '--bs-info-900', '--bs-info-950',
        '--bs-btn-info-bg', '--bs-btn-info-border-color',
        '--bs-btn-info-hover-bg', '--bs-btn-info-hover-border-color',
        '--bs-btn-info-active-bg', '--bs-btn-info-active-border-color',
        '--bs-info-text-emphasis', '--bs-info-bg-subtle', '--bs-info-border-subtle']
};

var _bodyBgVars = ['--bs-body-bg', '--bs-body-bg-rgb', '--bs-body-color', '--bs-body-color-rgb', '--x-component-bg'];

function applyThemeColor(colorName, hex) {
    var scale = generateColorScale(hex);
    var rgb = hexToRgb(hex);
    var root = document.documentElement.style;
    var n = colorName;
    root.setProperty('--bs-' + n, scale[500]);
    root.setProperty('--bs-' + n + '-rgb', rgb.r + ', ' + rgb.g + ', ' + rgb.b);
    root.setProperty('--bs-' + n + '-50', scale[50]);
    root.setProperty('--bs-' + n + '-100', scale[100]);
    root.setProperty('--bs-' + n + '-200', scale[200]);
    root.setProperty('--bs-' + n + '-300', scale[300]);
    root.setProperty('--bs-' + n + '-400', scale[400]);
    root.setProperty('--bs-' + n + '-500', scale[500]);
    root.setProperty('--bs-' + n + '-600', scale[600]);
    root.setProperty('--bs-' + n + '-700', scale[700]);
    root.setProperty('--bs-' + n + '-800', scale[800]);
    root.setProperty('--bs-' + n + '-900', scale[900]);
    root.setProperty('--bs-' + n + '-950', scale[950]);
    root.setProperty('--bs-btn-' + n + '-bg', scale[500]);
    root.setProperty('--bs-btn-' + n + '-border-color', scale[500]);
    root.setProperty('--bs-btn-' + n + '-hover-bg', scale[700]);
    root.setProperty('--bs-btn-' + n + '-hover-border-color', scale[700]);
    root.setProperty('--bs-btn-' + n + '-active-bg', scale[800]);
    root.setProperty('--bs-btn-' + n + '-active-border-color', scale[800]);
    root.setProperty('--bs-' + n + '-text-emphasis', scale[700]);
    root.setProperty('--bs-' + n + '-bg-subtle', scale[100]);
    root.setProperty('--bs-' + n + '-border-subtle', scale[200]);

    if (n === 'primary') {
        root.setProperty('--bs-link-color', scale[500]);
        root.setProperty('--bs-link-hover-color', scale[700]);
        root.setProperty('--bs-focus-ring-color', 'rgba(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ', 0.25)');

        // Additional Bootstrap variables for complete theme coverage
        root.setProperty('--bs-form-check-bg-checked', scale[500]);
        root.setProperty('--bs-form-check-border-color-checked', scale[500]);
        root.setProperty('--bs-nav-pills-link-active-bg', scale[500]);
        root.setProperty('--bs-nav-pills-link-active-color', '#fff');
        root.setProperty('--bs-pagination-active-bg', scale[500]);
        root.setProperty('--bs-pagination-active-border-color', scale[500]);
        root.setProperty('--bs-progress-bar-bg', scale[500]);
        root.setProperty('--bs-list-group-active-bg', scale[500]);
        root.setProperty('--bs-list-group-active-border-color', scale[500]);
        root.setProperty('--bs-dropdown-link-active-bg', scale[500]);
        root.setProperty('--bs-dropdown-link-active-color', '#fff');
        root.setProperty('--bs-badge-bg', scale[500]);
        root.setProperty('--bs-badge-color', '#fff');
        root.setProperty('--bs-link-decoration', 'none');
        root.setProperty('--bs-link-hover-decoration', 'none');
    }
}

function applyBodyBgColor(hex) {
    var rgb = hexToRgb(hex);
    var root = document.documentElement.style;
    // --bs-body-bg = 页面背景色（body 直接使用）
    root.setProperty('--bs-body-bg', hex);
    root.setProperty('--bs-body-bg-rgb', rgb.r + ', ' + rgb.g + ', ' + rgb.b);
    // --x-component-bg = 组件背景色（card/dropdown/modal 等通过 theme.css 覆盖引用）
    var lum = getRelativeLuminance(hex);
    if (lum > 0.5) {
        root.setProperty('--x-component-bg', '#fff');
        root.setProperty('--bs-body-color', '#212529');
        root.setProperty('--bs-body-color-rgb', '33, 37, 41');
    } else {
        root.setProperty('--x-component-bg', '#161616');
        root.setProperty('--bs-body-color', '#dee2e6');
        root.setProperty('--bs-body-color-rgb', '222, 226, 230');
    }
}

function removeThemeColor(colorName) {
    var vars = _themeColorVars[colorName];
    if (!vars) return;
    var root = document.documentElement.style;
    for (var i = 0; i < vars.length; i++) {
        root.removeProperty(vars[i]);
    }
}

function removeBodyBgColor() {
    var root = document.documentElement.style;
    for (var i = 0; i < _bodyBgVars.length; i++) {
        root.removeProperty(_bodyBgVars[i]);
    }
}

function applyAllCustomColors() {
    var names = ['primary', 'success', 'warning', 'danger', 'info'];
    for (var i = 0; i < names.length; i++) {
        var val = localStorage.getItem('theme-color-' + names[i]);
        if (val) {
            applyThemeColor(names[i], val);
        }
    }
    var theme = document.documentElement.getAttribute('data-bs-theme') || 'light';
    var bgKey = theme === 'dark' ? 'theme-color-body-bg-dark' : 'theme-color-body-bg-light';
    var bgVal = localStorage.getItem(bgKey);
    if (bgVal) {
        applyBodyBgColor(bgVal);
    }
}

function removeAllCustomColors() {
    var names = ['primary', 'success', 'warning', 'danger', 'info'];
    for (var i = 0; i < names.length; i++) {
        removeThemeColor(names[i]);
    }
    removeBodyBgColor();
}

function applyColorVariables(hex) {
    applyThemeColor('primary', hex);
}
