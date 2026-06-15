terraform {
  required_version = ">= 1.0"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
}

provider "aws" {
  region = var.aws_region
}

# VPC
resource "aws_vpc" "main" {
  cidr_block           = "10.0.0.0/16"
  enable_dns_hostnames = true

  tags = {
    Name = "gdwb-vpc"
  }
}

# Subnets
resource "aws_subnet" "public_1" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.0.1.0/24"
  availability_zone = "${var.aws_region}a"

  tags = {
    Name = "gdwb-public-1"
  }
}

resource "aws_subnet" "public_2" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.0.2.0/24"
  availability_zone = "${var.aws_region}b"

  tags = {
    Name = "gdwb-public-2"
  }
}

# RDS PostgreSQL
resource "aws_db_instance" "main" {
  identifier              = "gdwb-postgres"
  engine                  = "postgres"
  engine_version          = "15.3"
  instance_class          = "db.t3.micro"
  allocated_storage       = 20
  username                = var.db_username
  password                = var.db_password
  publicly_accessible     = false
  skip_final_snapshot     = true
  db_subnet_group_name    = aws_db_subnet_group.main.name
  vpc_security_group_ids  = [aws_security_group.db.id]

  tags = {
    Name = "gdwb-postgres"
  }
}

resource "aws_db_subnet_group" "main" {
  name       = "gdwb-db-subnet"
  subnet_ids = [aws_subnet.public_1.id, aws_subnet.public_2.id]

  tags = {
    Name = "gdwb-db-subnet"
  }
}

# S3 Bucket
resource "aws_s3_bucket" "assets" {
  bucket = "gdwb-assets-${data.aws_caller_identity.current.account_id}"

  tags = {
    Name = "gdwb-assets"
  }
}

resource "aws_s3_bucket_versioning" "assets" {
  bucket = aws_s3_bucket.assets.id

  versioning_configuration {
    status = "Enabled"
  }
}

# ECS Cluster
resource "aws_ecs_cluster" "main" {
  name = "gdwb-cluster"

  setting {
    name  = "containerInsights"
    value = "enabled"
  }

  tags = {
    Name = "gdwb-cluster"
  }
}

# Security Groups
resource "aws_security_group" "db" {
  name   = "gdwb-db-sg"
  vpc_id = aws_vpc.main.id

  ingress {
    from_port   = 5432
    to_port     = 5432
    protocol    = "tcp"
    cidr_blocks = ["10.0.0.0/16"]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "gdwb-db-sg"
  }
}

resource "aws_security_group" "alb" {
  name   = "gdwb-alb-sg"
  vpc_id = aws_vpc.main.id

  ingress {
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "gdwb-alb-sg"
  }
}

# Data source
data "aws_caller_identity" "current" {}

# Outputs
output "rds_endpoint" {
  value = aws_db_instance.main.endpoint
}

output "s3_bucket" {
  value = aws_s3_bucket.assets.id
}

output "ecs_cluster_name" {
  value = aws_ecs_cluster.main.name
}
