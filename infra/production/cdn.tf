/* CloudFront: one HTTPS address for everything (build plan 5.2's review, item 8: the service worker
   expects /api on the same origin as the app). The PWA comes from its bucket; the API, the admin
   panel, and the panel's own assets come from the host. Nothing from the host is cached, and every
   header, cookie, and query string is passed on to it. CloudFront also adds a secret header that
   nginx requires, so a request that reaches the host's address directly is refused even if it got
   past the security group. */

data "aws_cloudfront_cache_policy" "disabled" {
  name = "Managed-CachingDisabled"
}

data "aws_cloudfront_cache_policy" "optimized" {
  name = "Managed-CachingOptimized"
}

data "aws_cloudfront_origin_request_policy" "all_viewer" {
  name = "Managed-AllViewerExceptHostHeader"
}

resource "random_password" "origin" {
  length  = 40
  special = false
}

resource "aws_cloudfront_origin_access_control" "frontend" {
  name                              = "connected-frontend"
  origin_access_control_origin_type = "s3"
  signing_behavior                  = "always"
  signing_protocol                  = "sigv4"
}

# The PWA routes in the browser: any path without a file extension is the app shell.
resource "aws_cloudfront_function" "spa" {
  name    = "connected-spa"
  runtime = "cloudfront-js-2.0"
  publish = true
  code    = file("${path.module}/spa-rewrite.js")
}

locals {
  frontend_origin = "frontend"
  api_origin      = "api"

  # Everything the host answers. Livewire 4 serves its updates and scripts under a hashed prefix
  # (livewire-808cf2ba/...), hence the wildcard rather than /livewire/*. /css/app and /fonts/app
  # are the admin panel's own look, published there as Filament assets (AdminPanelProvider).
  api_paths = [
    "/api/*", "/up", "/admin", "/admin/*", "/livewire-*", "/filament/*",
    "/css/filament/*", "/js/filament/*", "/fonts/filament/*", "/css/app/*", "/fonts/app/*",
  ]
}

resource "aws_cloudfront_distribution" "main" {
  enabled             = true
  comment             = "ConnectED"
  default_root_object = "index.html"
  http_version        = "http2and3"
  # Edges in Africa, Europe, the Middle East, Asia, and North America; not South America or Oceania.
  price_class = "PriceClass_200"

  origin {
    origin_id                = local.frontend_origin
    domain_name              = aws_s3_bucket.frontend.bucket_regional_domain_name
    origin_access_control_id = aws_cloudfront_origin_access_control.frontend.id
  }

  # Plain HTTP to the host until there is a domain to give it a certificate (ADR 0015); the
  # browser's connection to CloudFront is HTTPS throughout.
  origin {
    origin_id   = local.api_origin
    domain_name = aws_eip.app.public_dns

    custom_origin_config {
      http_port              = 80
      https_port             = 443
      origin_protocol_policy = "http-only"
      origin_ssl_protocols   = ["TLSv1.2"]
      origin_read_timeout    = 60
    }

    custom_header {
      name  = "X-Origin-Verify"
      value = random_password.origin.result
    }
  }

  default_cache_behavior {
    target_origin_id       = local.frontend_origin
    viewer_protocol_policy = "redirect-to-https"
    allowed_methods        = ["GET", "HEAD", "OPTIONS"]
    cached_methods         = ["GET", "HEAD"]
    cache_policy_id        = data.aws_cloudfront_cache_policy.optimized.id
    compress               = true

    function_association {
      event_type   = "viewer-request"
      function_arn = aws_cloudfront_function.spa.arn
    }
  }

  dynamic "ordered_cache_behavior" {
    for_each = local.api_paths

    content {
      path_pattern             = ordered_cache_behavior.value
      target_origin_id         = local.api_origin
      viewer_protocol_policy   = "redirect-to-https"
      allowed_methods          = ["GET", "HEAD", "OPTIONS", "PUT", "POST", "PATCH", "DELETE"]
      cached_methods           = ["GET", "HEAD"]
      cache_policy_id          = data.aws_cloudfront_cache_policy.disabled.id
      origin_request_policy_id = data.aws_cloudfront_origin_request_policy.all_viewer.id
      compress                 = true
    }
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    cloudfront_default_certificate = true
  }
}
