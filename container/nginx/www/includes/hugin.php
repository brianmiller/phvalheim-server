<?php
/**
 * Hugin. One raven, one drawing.
 *
 * He appears in at least three places (the sidebar button, the panel header, and inside
 * every answer while the model works) and the first cut had the artwork copy-pasted between
 * a PHP heredoc and a JavaScript string builder. Two copies of the same bird drift: change
 * the beak in one and the panel header quietly keeps the old one forever, because nothing
 * renders them side by side.
 *
 * So the SVG lives here, once. PHP echoes it wherever it is needed, and the JS clones a
 * node already in the DOM rather than re-describing him — see aiHuginNode() in admin/index.php.
 *
 * Shape notes, since "cuter" is a real requirement and not a whim:
 *   - One silhouette path, not head+body. Two overlapping shapes leave a seam wherever the
 *     stroke of the upper one crosses the fill of the lower, which reads as a crack.
 *   - Big head, small beak, low round belly. Neoteny is the whole trick.
 *   - The eye needs a glint. A flat disc is a shark's eye; one off-centre highlight is the
 *     single cheapest thing that makes a shape look alive.
 *   - The head tuft exists to have something to wiggle.
 */
function huginSvg($extraClass = 'idle', $size = 34) {
    $s = (int)$size;
    return '<svg class="ai-hugin ' . htmlspecialchars($extraClass, ENT_QUOTES) . '"'
         .     ' width="' . $s . '" height="' . $s . '" viewBox="0 0 40 40" aria-hidden="true">'
         .   '<g class="hg-all">'
         .     '<path class="hg-tail" d="M30.4 27.6c3.2.4 5.6 1.9 7.2 4.4-2.8 1-5.6.5-8-1.4z"/>'
         .     '<path class="hg-body" d="M20 3.6c-7.4 0-12.8 5.3-12.8 12.3 0 3.5 1 5.5 1 8.4 0'
         .       ' 5.8 4.8 9.9 11.8 9.9s11.8-4.1 11.8-9.9c0-2.9 1-4.9 1-8.4C32.8 8.9 27.4 3.6 20 3.6z"/>'
         .     '<path class="hg-tuft" d="M17.6 4.2c.4-2.1 1.7-3.4 3.8-3.9-.8 1.4-.8 2.6-.1 3.7z"/>'
         .     '<path class="hg-wing" d="M22.4 18.4c4.6.5 7.6 3.1 8.4 7.4-3.3.9-6.3-.3-8.7-3z"/>'
         .     '<path class="hg-beak" d="M9.9 13.4 3.1 16.1l6.8 2.8z"/>'
         .     '<circle class="hg-eye"   cx="14.3" cy="13.1" r="3.3"/>'
         .     '<circle class="hg-glint" cx="15.5" cy="11.9" r="1.15"/>'
         .     '<path class="hg-feet" d="M16 33.9v2.7M23 33.9v2.7"/>'
         .   '</g>'
         . '</svg>';
}
