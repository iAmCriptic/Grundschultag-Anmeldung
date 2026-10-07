(function () {
    function initRichEditors() {
        if (typeof tinymce === 'undefined') {
            return;
        }

        var nodes = document.querySelectorAll('textarea.rich-editor');
        if (!nodes.length) {
            return;
        }

        tinymce.init({
            selector: 'textarea.rich-editor',
            menubar: false,
            branding: false,
            promotion: false,
            statusbar: false,
            height: 280,
            convert_urls: false,
            entity_encoding: 'raw',
            plugins: 'lists link autoresize',
            toolbar:
                'bold italic underline strikethrough | forecolor backcolor | fontsize | ' +
                'alignleft aligncenter alignright | bullist numlist | link | removeformat',
            font_size_formats: '12px 14px 16px 18px 20px 24px 28px 32px',
            content_style:
                'body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; font-size: 16px; line-height: 1.5; }',
            setup: function (editor) {
                editor.on('change input Undo Redo', function () {
                    editor.save();
                });
            }
        });
    }

    function refreshVisibleEditors() {
        if (typeof tinymce === 'undefined') {
            return;
        }
        tinymce.editors.forEach(function (editor) {
            try {
                editor.dispatch('ResizeEditor');
            } catch (e) {
                // ignore
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRichEditors);
    } else {
        initRichEditors();
    }

    document.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-settings-tab]');
        if (!tab) {
            return;
        }
        window.setTimeout(refreshVisibleEditors, 50);
    });

    window.AppRichEditor = {
        refresh: refreshVisibleEditors
    };
})();
