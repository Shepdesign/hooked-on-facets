// jsdom misses a handful of DOM APIs the public-runtime modules touch
// during normal use. Stubbing the no-op surface here keeps individual
// tests focused on behavior instead of environment plumbing.

if (typeof Element !== 'undefined' && !Element.prototype.setPointerCapture) {
    Element.prototype.setPointerCapture = function () {};
    Element.prototype.releasePointerCapture = function () {};
    Element.prototype.hasPointerCapture = function () { return false; };
}
