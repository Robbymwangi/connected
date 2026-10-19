// CloudFront viewer-request function on the PWA's behaviour (infra/production/cdn.tf). The app
// routes in the browser, so /classes/4W has no file of its own: a path whose last segment has no
// extension is served the app shell. Files (/assets/app.js, /sw.js) pass through unchanged.
function handler(event) {
  var request = event.request;
  var last = request.uri.split('/').pop();

  if (last.indexOf('.') === -1) {
    request.uri = '/index.html';
  }

  return request;
}
