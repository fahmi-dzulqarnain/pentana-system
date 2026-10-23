// Password strength scoring for the password meter (resources/views/components/password-strength.blade.php).
//
// Registered as a Filament asset like passkeys.js, so it loads on panel pages and on the claim
// page (@filamentScripts). This file is tiny; the zxcvbn dictionaries (several hundred KB) are a
// separate chunk fetched the first time someone focuses a password field.
let checker;

function load() {
    checker ??= Promise.all([
        import('@zxcvbn-ts/core'),
        import('@zxcvbn-ts/language-common'),
        import('@zxcvbn-ts/language-en'),
    ]).then(([core, common, en]) => new core.ZxcvbnFactory({
        dictionary: { ...common.dictionary, ...en.dictionary },
        graphs: common.adjacencyGraphs,
        translations: en.translations,
    }));

    return checker;
}

window.pentanaPasswordStrength = {
    preload: load,
    // zxcvbn's 0-4 score; userInputs (the member's name, email) count as easy to guess.
    async score(password, userInputs = []) {
        return (await load()).check(password, userInputs).score;
    },
};
