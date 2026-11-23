// SocialScore Pixel loader; the service's own script, see "External services" in readme.txt.
(function (win, doc) {
	"use strict";
	win.ssPixel = win.ssPixel || [];
	var script = doc.createElement("script");
	script.async = true;
	script.src = "https://pixel.socialscore.io/v1/p.js";
	script.setAttribute("data-site", "{{SITE_ID}}");
	script.setAttribute("data-consent-source", "{{CONSENT_SOURCE}}");
	doc.head.appendChild(script);
})(window, document);
