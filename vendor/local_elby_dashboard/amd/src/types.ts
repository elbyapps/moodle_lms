// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * TypeScript type definitions for Elby Dashboard.
 *
 * @module     local_elby_dashboard/types
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export interface UserData {
    id: number;
    fullname: string;
    firstname: string;
    lastname: string;
    email: string;
    avatar: string;
    roles: string[];
    isAdmin?: boolean;
    canManage?: boolean;
}

export interface Teacher {
    id: number;
    firstname: string;
    lastname: string;
    fullname: string;
    email: string;
    avatar?: string;
}

export interface StatsData {
    totalCourses: number;
    totalUsers: number;
    totalEnrollments: number;
    totalActivities: number;
    activeUsers?: number;
    totalTeachers: number;
    totalStudents: number;
    activeToday: number;
    activeThisWeek: number;
    atRisk: number;
    neverLoggedIn: number;
    totalSchools: number;
    maleStudents: number;
    femaleStudents: number;
    maleTeachers: number;
    femaleTeachers: number;
    gradeDistribution: Array<{ label: string; count: number }>;
    programDistribution: Array<{ label: string; count: number }>;
    positionDistribution: Array<{ label: string; count: number }>;
}

export interface MenuItem {
    id: string;
    name: string;
    url: string;
    icon: string;
}

export interface SidenavConfig {
    title: string;
    logoUrl: string | null;
}

export interface ThemeConfig {
    // Colors
    sidenavAccentColor: string;
    statCard1Color: string;
    statCard2Color: string;
    statCard3Color: string;
    statCard4Color: string;
    chartPrimaryColor: string;
    chartSecondaryColor: string;
    // Header options
    showSearchBar: boolean;
    showNotifications: boolean;
    showUserProfile: boolean;
    // Menu visibility
    menuVisibility: Record<string, boolean>;
}

export type PageId = 'home' | 'completion' | 'courses' | 'schools' | 'school_detail' | 'students' | 'teachers' | 'traffic' | 'accesslog' | 'admin' | 'blended_learning' | 'rise';

// RISE recruitment dashboard types.
export interface RiseCampaignStats {
    total: number;
    female: number;
    male: number;
    pwd: number;
    refugee: number;
    shortlisted: number;
    hired: number;
    enrolled: number;
}

export interface RiseCampaign {
    _id: string;
    name: string;
    roleName?: string;
    status: string;
    slug?: string;
    type?: string;
    createdBy?: string;
    createdAt?: string;
    deadline?: string | null;
    targetDistricts?: string[];
    targetDistrictCount?: number;
    stats: RiseCampaignStats;
}

export interface RisePagination {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
}

export interface RiseReview {
    reviewerName?: string;
    comment?: string;
    date?: string;
    score?: number;
}

export interface RiseApplicant {
    _id: string;
    campaignId: string;
    fullName?: string;
    gender?: string;
    phone?: string;
    nid?: string;
    status?: string;
    district?: string;
    sector?: string;
    cell?: string;
    village?: string;
    dateOfBirth?: string;
    graduationYear?: number;
    systemScore?: number;
    totalScore?: number;
    reviewerScore?: number;
    createdAt?: string;
    degreeLink?: string | null;
    missingDocuments?: string[];
    location?: {
        provinceCode?: string;
        districtCode?: string;
        districtName?: string;
        sectorName?: string;
    };
    formResponses?: Record<string, string>;
    rawFormData?: Record<string, string>;
    reviews?: RiseReview[];
    [key: string]: any;
}

export interface RiseAttachment {
    label: string;
    url: string;
    ext: string;
}

export type NesaStatus = 'approved' | 'rejected' | 'action_requested' | 'pending';

export interface RiseNesaReview {
    nesastatus: NesaStatus;
    nesaindexnumber?: string;
    nidverified: number;
    comment: string;
    reviewedby?: number;
    timemodified?: number;
}

export interface RiseNidField {
    field: string;
    app: string;
    nida: string;
    status: 'match' | 'diff' | 'na';
}

export interface RiseNidValidation {
    found: boolean;
    match: boolean;
    namematch: boolean;
    dobmatch: boolean | null;
    fields: RiseNidField[];
}

// Blended Learning types
export interface BlendedLearningMetrics {
    category_id: number;
    category_name: string;
    total_courses: number;
    total_enrolled_students: number;
    total_teachers: number;
    active_student_rate: number;
    active_students: number;
    activity_participation_rate: number;
    students_with_activity: number;
    avg_login_frequency: number;
    avg_learning_time_minutes: number;
    digital_assessment_performance: number;
    assignment_submission_rate: number;
    students_submitted_assignments: number;
    quiz_participation_rate: number;
    students_attempted_quizzes: number;
    course_completion_rate: number;
    students_completed_courses: number;
    forum_interaction_rate: number;
    students_with_forum_posts: number;
    teacher_lms_usage_rate: number;
    teachers_uploading_content: number;
    teachers_with_active_courses: number;
    teacher_lms_activity_rate: number;
    teachers_with_activity: number;
    digital_course_availability: number;
    courses_with_materials: number;
    period_start: number;
    period_end: number;
}

export interface BlendedLearningSchool {
    school_code: string;
    school_name: string;
    student_count: number;
    teacher_count: number;
    avg_completion_rate: number;
    active_student_rate: number;
}

export interface BlendedLearningStudent {
    userid: number;
    fullname: string;
    school_name: string;
    school_code: string;
    courses_enrolled: number;
    completion_pct: number;
    last_access: number;
}

export interface BlendedLearningTeacher {
    userid: number;
    fullname: string;
    school_name: string;
    school_code: string;
    courses_teaching: number;
    activity_rate: number;
}

// Metrics types
export interface SchoolMetrics {
    period_start: number;
    period_end: number;
    period_type: string;
    total_enrolled: number;
    total_active: number;
    total_inactive: number;
    new_enrollments: number;
    avg_actions_per_student: number;
    avg_active_days: number;
    avg_time_spent_minutes: number;
    total_resource_views: number;
    avg_resources_per_student: number;
    total_submissions: number;
    total_quiz_attempts: number;
    avg_assignment_score: number;
    avg_quiz_score: number;
    submission_rate: number;
    avg_course_progress: number;
    completion_rate: number;
    high_engagement_count: number;
    medium_engagement_count: number;
    low_engagement_count: number;
    at_risk_count: number;
}

export interface StudentMetric {
    userid: number;
    fullname: string;
    sdms_id: string;
    program: string;
    program_code: string;
    position: string;
    gender: string;
    age: number | null;
    class_grade: string;
    school_name: string;
    school_code: string;
    last_access: number;
    active_days: number;
    total_actions: number;
    quizzes_avg_score: number | null;
    course_progress: number | null;
    enrolled_courses: number;
    status: string;
}

export interface StudentListSummary {
    total: number;
    active_count: number;
    at_risk_count: number;
    avg_quiz_score: number | null;
}

export interface ProgramOption {
    code: string;
    name: string;
}

export interface StudentListResponse {
    students: StudentMetric[];
    total_count: number;
    page: number;
    perpage: number;
    summary: StudentListSummary;
    programs: ProgramOption[];
}

export interface EngagementDistribution {
    high_engagement_count: number;
    medium_engagement_count: number;
    low_engagement_count: number;
    at_risk_count: number;
    total_enrolled: number;
}

// Course report types
export interface CourseListItem {
    id: number;
    shortname: string;
    fullname: string;
    enrolled_count: number;
}

export interface CourseCategory {
    category_id: number;
    category_name: string;
    courses: CourseListItem[];
}

export interface CategoryNode {
    id: number;
    name: string;
    parent: number;
    children: CategoryNode[];
    courses: CourseListItem[];
}

export interface SectionStat {
    section_number: number;
    section_name: string;
    completion_rate: number;
    average_grade?: number;
    grademax?: number;
}

export interface SchoolReport {
    school_code: string;
    school_name: string;
    student_count: number;
    sections: SectionStat[];
}

export interface OverviewSection {
    section_number: number;
    section_name: string;
    completion_rate: number;
}

export interface CourseReport {
    courseid: number;
    course_name: string;
    course_shortname?: string;
    total_enrolled: number;
    total_schools: number;
    overview_sections: OverviewSection[];
    schools: SchoolReport[];
}

export interface AcademicYear {
    value: number;      // Start year (e.g., 2024)
    label: string;      // Display format (e.g., "2024-2025")
}

export interface CoursesReportData {
    courses_list: CategoryNode[];
    selected_courseid: number;
    course_report: CourseReport | null;
    available_years: AcademicYear[];
    selected_year: number;
}

// Trades report types
export interface TradeSchool {
    school_name: string;
    school_code: string;
}

export interface TradeData {
    trade_name: string;
    trade_code: string;
    trade_desc: string;
    school_count: number;
    schools: TradeSchool[];
}

// School user counts types
export interface SchoolUserCounts {
    school_code: string;
    school_name: string;
    student_count: number;
    teacher_count: number;
}

// School demographics types
export interface GenderBreakdown {
    total: number;
    male: number;
    female: number;
}

export interface AgeBucket {
    label: string;
    count: number;
}

export interface SchoolDemographics {
    success: boolean;
    error: string;
    students: GenderBreakdown;
    teachers: GenderBreakdown;
    age_distribution: AgeBucket[];
}

// School info hierarchy types
export interface SchoolInfoClassgroup {
    class_id: string;
    class_name: string;
}

export interface SchoolInfoGrade {
    grade_code: string;
    grade_name: string;
    classgroups: SchoolInfoClassgroup[];
}

export interface SchoolInfoCombination {
    combination_code: string;
    combination_name: string;
    combination_desc: string;
    grades: SchoolInfoGrade[];
}

export interface SchoolInfoLevel {
    level_id: string;
    level_name: string;
    level_desc: string;
    combinations: SchoolInfoCombination[];
}

export interface SchoolInfoResponse {
    success: boolean;
    error: string;
    school_code: string;
    school_name: string;
    region_code: string;
    is_active: number;
    has_tvet: number;
    academic_year: string;
    levels: SchoolInfoLevel[];
    last_synced: number;
}

// Traffic report types
export interface TrafficDataPoint {
    period_label: string;
    total_actions: number;
    unique_users: number;
    period_start: number;
}

export interface HeatmapDataPoint {
    day_of_week: number;  // 0=Mon..6=Sun
    hour_of_day: number;  // 0-23
    action_count: number;
}

export interface TopActiveUser {
    userid: number;
    fullname: string;
    school_name: string;
    school_code: string;
    user_type: string;
    total_actions: number;
    active_days: number;
}

export interface TrafficBySchool {
    school_code: string;
    school_name: string;
    total_actions: number;
    unique_users: number;
}

export interface ActionBreakdown {
    component: string;
    label: string;
    action_count: number;
}

// Unlinked user type (for admin SDMS linking)
export interface UnlinkedUser {
    userid: number;
    fullname: string;
    username: string;
    email: string;
}

export interface UnlinkedUsersResponse {
    users: UnlinkedUser[];
    total_count: number;
    page: number;
    perpage: number;
}

// Access log types
export interface AccessLogEntry {
    user_fullname: string;
    sdms_id: string;
    user_type: string;
    school_name: string;
    school_code: string;
    course_name: string;
    access_time: number;
    action: string;
    target: string;
}

export interface AccessLogResponse {
    entries: AccessLogEntry[];
    total_count: number;
    page: number;
    perpage: number;
}

// School courses report types (for SchoolDetail)
export interface SchoolTradeCourse {
    id: number;
    fullname: string;
    enrolled_count: number;
}

export interface SchoolTradeLevel {
    level_number: number;
    level_name: string;
    student_count: number;
    courses: SchoolTradeCourse[];
}

export interface SchoolTrade {
    code: string;
    name: string;
    levels: SchoolTradeLevel[];
}

export interface SchoolCoursesReport {
    school_code: string;
    school_name: string;
    trades: SchoolTrade[];
}

// Enrollment coverage types
export interface EnrollmentCoverageEntry {
    combination_code: string;
    combination_name: string;
    level_number: number;
    level_name: string;
    sdms_student_count: number;
    category_id: number;
    category_name: string;
    course_count: number;
    enrolled_student_count: number;
    coverage_status: 'mapped' | 'unmapped' | 'partial';
}

export interface EnrollmentCoverageSummary {
    total_combos: number;
    mapped_combos: number;
    unmapped_combos: number;
    total_sdms_students: number;
    total_enrolled_students: number;
}

export interface EnrollmentCoverageResponse {
    entries: EnrollmentCoverageEntry[];
    summary: EnrollmentCoverageSummary;
}

// Enrollment logs types
export interface EnrollmentLogEntry {
    id: number;
    userid: number;
    user_fullname: string;
    entity_id: string;
    operation: string;
    details: string;
    timecreated: number;
}

export interface EnrollmentLogsSummary {
    total_enrollments: number;
    total_skips: number;
    last_enrollment_time: number;
}

export interface EnrollmentLogsResponse {
    logs: EnrollmentLogEntry[];
    total_count: number;
    page: number;
    perpage: number;
    summary: EnrollmentLogsSummary;
}

// Extended trade data (with category/course info)
export interface TradeLevelData {
    level_number: number;
    category_id: number;
    category_name: string;
    course_count: number;
    total_enrolled: number;
}

export interface ExtendedTradeData extends TradeData {
    levels: TradeLevelData[];
    total_course_count: number;
    has_category_mapping: boolean;
}
