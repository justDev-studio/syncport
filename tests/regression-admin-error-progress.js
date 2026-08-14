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
const progressBar = {
    value: 0,
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
            migrationErrors: 'Migration completed with %1$d error(s): %2$s',
            operationFailed: 'The operation failed.',
            working: 'In progress',
        },
    },
};
const fetch = async () => ({
    json: async () => ({
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
    }),
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
    if (!classes.has('is-error')) throw new Error('Error class was not applied.');
    if (progressBar.value !== 100) throw new Error('Error progress remains indeterminate.');
    if (progressState.textContent !== 'The operation failed.') throw new Error('Terminal error state is missing.');
    if ((noticeText.match(/checksum does not match/g) || []).length !== 1) throw new Error('Duplicate errors were not collapsed.');
    console.log('Migration errors produce a terminal progress state.');
})().catch((error) => {
    console.error(error.message);
    process.exit(1);
});
