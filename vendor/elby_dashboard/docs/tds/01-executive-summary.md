# 1. Executive Summary

This document specifies the technical design for integrating SDMS (Student Data Management System) data with RTB's Moodle LMS to provide real-time analytics on student engagement, assessment performance, and content usage at the school level.

The solution addresses the constraint of SDMS providing only single-record lookup endpoints (no bulk/list APIs) by implementing an opportunistic sync strategy that populates local cache tables as users interact with the LMS.

**Key Deliverables:**
- Local cache of SDMS school and user data within Moodle
- Pre-computed periodic activity metrics to avoid expensive log table queries
- School-level aggregate reports for administrators
- Dashboard interface integrated into existing RTB dashboard plugin
