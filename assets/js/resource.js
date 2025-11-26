document.addEventListener('DOMContentLoaded', function () {
    var copyButtons = document.querySelectorAll('.resource-copy-btn');

    copyButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var code = this.getAttribute('data-code');
            if (code) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(code).then(function () {
                        showTooltip(btn, '已复制');
                    }).catch(function (err) {
                        fallbackCopyTextToClipboard(code, btn);
                    });
                } else {
                    fallbackCopyTextToClipboard(code, btn);
                }
            }
        });
    });

    function fallbackCopyTextToClipboard(text, btn) {
        var textArea = document.createElement("textarea");
        textArea.value = text;

        // Ensure it's not visible but part of DOM
        textArea.style.top = "0";
        textArea.style.left = "0";
        textArea.style.position = "fixed";

        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            var successful = document.execCommand('copy');
            var msg = successful ? '已复制' : '复制失败';
            showTooltip(btn, msg);
        } catch (err) {
            showTooltip(btn, '复制失败');
        }

        document.body.removeChild(textArea);
    }

    function showTooltip(btn, msg) {
        var originalText = btn.innerText;
        btn.innerText = msg;
        btn.disabled = true;
        setTimeout(function () {
            btn.innerText = originalText;
            btn.disabled = false;
        }, 1500);
    }
});
