const fs = require('node:fs');
const vm = require('node:vm');

const listeners = {};
const classes = new Set();
const progressRegion = {
    hidden: true,
    classList: {
        toggle(name, enabled) {
            if (enabled) classes.add(name);
            else classes.delete(name);
        },
    },
};
const progressValues = [];
let progressValue = 0;
const progressBar = {
    get value() {
        return progressValue;
    },
    set value(value) {
        progressValue = value;
        progressValues.push(value);
    },
    removeAttribute(name) {
        if (name === 'value') this.value = null;
    },
};
const progressLabel = { textContent: '' };
const progressState = { textContent: '' };
let noticeText = '';
const notice = {
    replaceChildren(wrapper) {
        noticeText = wrapper.children[0].textContent;
    },
};
const document = {
    querySelector(selector) {
        return {
            '#syncport-notice': notice,
            '#syncport-progress': progressRegion,
            '#syncport-progress-bar': progressBar,
            '#syncport-progress-label': progressLabel,
            '#syncport-progress-state': progressState,
        }[selector] || null;
    },
    querySelectorAll() {
        return [];
    },
    createElement() {
        return {
            children: [],
            className: '',
            textContent: '',
            append(child) {
                this.children.push(child);
            },
        };
    },
    addEventListener(type, listener) {
        listeners[type] = listener;
    },
};
const button = {
    dataset: { applyOperation: 'operation-id' },
    disabled: false,
    setAttribute() {},
    removeAttribute() {},
    remove() {},
};
const window = {
    confirm: () => true,
    syncportAdmin: {
        ajaxUrl: '/ajax',
        nonce: 'test-nonce',
        strings: {
            applyingMigration: 'Applying migration…',
            databaseProgress: 'Migrating database rows: %1$d of %2$d…',
            migrationErrors: 'Migration completed with %1$d error(s): %2$s',
            operationFailed: 'The operation failed.',
            working: 'In progress',
        },
    },
};
let fetchCalls = 0;
const responses = [
    {
        success: true,
        data: {
            status: 'running',
            result: {
                errors: [],
                database: { processed: 100, total: 200, percent: 50 },
            },
        },
    },
    {
        success: true,
        data: {
            status: 'completed_with_errors',
            result: {
                errors: [
                    { message: 'The downloaded media checksum does not match the manifest.' },
                    { message: 'The downloaded media checksum does not match the manifest.' },
                ],
            },
        },
    },
];
const fetch = async () => ({
    json: async () => responses[fetchCalls++],
});

vm.runInNewContext(fs.readFileSync('assets/admin.js', 'utf8'), {
    URLSearchParams,
    console,
    document,
    fetch,
    FormData,
    Option: class {},
    window,
});

(async () => {
    await listeners.click({ target: { closest: () => button } });
    if (fetchCalls !== 2 || !progressValues.includes(50)) throw new Error('Database chunks did not report determinate progress.');
    if (!classes.has('is-error')) throw new Error('Error class was not applied.');
    if (progressBar.value !== 100) throw new Error('Error progress remains indeterminate.');
    if (progressState.textContent !== 'The operation failed.') throw new Error('Terminal error state is missing.');
    if ((noticeText.match(/checksum does not match/g) || []).length !== 1) throw new Error('Duplicate errors were not collapsed.');
    console.log('Migration errors produce a terminal progress state.');
})().catch((error) => {
    console.error(error.message);
    process.exit(1);
});
