-- Migration: 002_add_constraints.sql
-- Date: 2026-01-03
-- Purpose: Add data integrity constraints
-- Run this on Neon PostgreSQL dashboard or via psql

-- Email uniqueness (if not already exists)
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'users_email_unique'
    ) THEN
        ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email);
    END IF;
END $$;

-- Role enum constraint
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'users_role_check'
    ) THEN
        ALTER TABLE users ADD CONSTRAINT users_role_check 
            CHECK (role IN ('SUPER_ADMIN', 'MANAGER', 'SALES_REP'));
    END IF;
END $$;

-- Lead status enum constraint
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'leads_status_check'
    ) THEN
        ALTER TABLE leads ADD CONSTRAINT leads_status_check 
            CHECK (status IN ('NEW', 'CONTACTED', 'FOLLOW_UP', 'INTERESTED', 'WON', 'LOST'));
    END IF;
END $$;

-- Task status enum constraint
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'tasks_status_check'
    ) THEN
        ALTER TABLE tasks ADD CONSTRAINT tasks_status_check 
            CHECK (status IN ('OPEN', 'DONE', 'SKIPPED'));
    END IF;
END $$;

-- Task channel enum constraint
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'tasks_channel_check'
    ) THEN
        ALTER TABLE tasks ADD CONSTRAINT tasks_channel_check 
            CHECK (channel IN ('call', 'whatsapp', 'email'));
    END IF;
END $$;
