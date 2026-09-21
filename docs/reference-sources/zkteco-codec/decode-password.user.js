// ==UserScript==
// @name         ZKAccess 5.3 - Decode Password + Logs Safe
// @match        http://zkaccess.example.invalid/*
// @grant        none
// ==/UserScript==

(function () {
    'use strict';

    const table = {
        AF:'5', BF:'4', CF:'7', DF:'6', EF:'1',
        FF:'0', GF:'3', HF:'2', MF:'9', NF:'8'
    };

    function decode(v) {
        if (!v) return null;
        v = v.trim().toUpperCase();

        if (v.length % 2 !== 0) return null;

        let pin = '';
        for (let i = 0; i < v.length; i += 2) {
            const pair = v.substring(i, i + 2);
            if (!(pair in table)) return null;
            pin += table[pair];
        }
        return pin;
    }

    function stylePasswordField(p) {
        p.type = "text";
        p.style.background = "#fff7b2";
        p.style.fontWeight = "bold";
    }

    function updatePasswordColor(p) {
        if (!p) return;

        stylePasswordField(p);

        if (!p.value) {
            p.dataset.zkStartedEmpty = "1";
            p.style.color = "red";
            return;
        }

        if (p.dataset.zkStartedEmpty === "1" && !p.dataset.zkOriginalPin) {
            p.style.color = "#00AA00";
            return;
        }

        if (p.dataset.zkOriginalPin && p.value === p.dataset.zkOriginalPin) {
            p.style.color = "red";
        } else {
            p.style.color = "#00AA00";
        }
    }

    function decodePasswordField() {
        const p = document.querySelector("#id_Password");
        if (!p) return;

        stylePasswordField(p);

        const value = p.value;

        if (!value) {
            p.dataset.zkStartedEmpty = "1";
            p.style.color = "red";
            return;
        }

        if (p.dataset.zkDecoded === "1") {
            updatePasswordColor(p);
            return;
        }

        if (/^\d+$/.test(value)) {
            if (p.dataset.zkStartedEmpty === "1") {
                p.style.color = "#00AA00";
                return;
            }

            p.dataset.zkOriginalPin = value;
            p.dataset.zkDecoded = "1";
            updatePasswordColor(p);
            return;
        }

        const pin = decode(value);
        if (!pin) return;

        p.dataset.zkOriginalPassword = value;
        p.dataset.zkOriginalPin = pin;
        p.dataset.zkDecoded = "1";

        p.value = pin;
        p.setAttribute("value", pin);

        updatePasswordColor(p);
    }

    function decodeLogLine(text) {
        return text.replace(
            /Password\(([A-Z]{2,16})->([A-Z]{0,16})\)/g,
            function (match, oldCode, newCode) {
                const oldPin = decode(oldCode);
                const newPin = decode(newCode);

                if (!oldPin && !newPin) return match;

                return "Password(" + (oldPin || oldCode) + "->" + (newPin || newCode) + ")";
            }
        );
    }

    function decodeTextNodes(root) {
        const walker = document.createTreeWalker(
            root,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function (node) {
                    if (!node.nodeValue.includes("Password(")) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    return NodeFilter.FILTER_ACCEPT;
                }
            }
        );

        const nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }

        nodes.forEach(node => {
            const oldText = node.nodeValue;
            const newText = decodeLogLine(oldText);

            if (newText !== oldText) {
                node.nodeValue = newText;
            }
        });
    }

    document.addEventListener("input", function (e) {
        if (e.target && e.target.id === "id_Password") {
            e.target.dataset.zkUserTyped = "1";
            updatePasswordColor(e.target);
        }
    }, true);

    document.addEventListener("change", function (e) {
        if (e.target && e.target.id === "id_Password") {
            updatePasswordColor(e.target);
        }
    }, true);

    function run() {
        decodePasswordField();
        decodeTextNodes(document.body);
    }

    setInterval(run, 500);
})();