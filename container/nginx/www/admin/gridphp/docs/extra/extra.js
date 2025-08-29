// Chatra widget
(function(d, w, c) {
    w.ChatraID = 'de92EMe5e2YEPcdJ3';
    var s = d.createElement('script');
    w[c] = w[c] || function() {
        (w[c].q = w[c].q || []).push(arguments);
    };
    s.async = true;
    s.src = (d.location.protocol === 'https:' ? 'https:': 'http:')
    + '//call.chatra.io/chatra.js';
    if (d.head) d.head.appendChild(s);
})(document, window, 'Chatra');

// add header link
// document.addEventListener("DOMContentLoaded", function () {
//     const header = document.querySelector("nav.md-header__inner"); // Select header
//     if (header) {
//         let link = document.createElement("a");
//         link.href = "https://www.gridphp.com"; // Replace with your link
//         link.textContent = " » Visit Main Site";
//         link.style.marginLeft = "10px";
//         link.style.fontSize = "16px";
//         link.style.textDecoration = "none";
//         link.style.border = "1px solid #aaa";
//         link.style.padding = "6px";
//         link.style.borderRadius = "5px";

//         header.appendChild(link);
//     }
// });