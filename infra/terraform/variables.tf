variable "aws_region" {
  description = "AWS region"
  default     = "us-east-1"
}

variable "db_username" {
  description = "Database master username"
  default     = "gdwbadmin"
  sensitive   = true
}

variable "db_password" {
  description = "Database master password"
  sensitive   = true
}

variable "stripe_secret_key" {
  description = "Stripe secret key"
  sensitive   = true
}

variable "cloudflare_api_key" {
  description = "Cloudflare API key"
  sensitive   = true
}

variable "auth0_domain" {
  description = "Auth0 domain"
}

variable "auth0_client_id" {
  description = "Auth0 client ID"
  sensitive   = true
}
