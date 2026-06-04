<?php
// employee_data.php - Load employee data from CSV

class EmployeeData {
    private $employees = [];
    private $csv_file = __DIR__ . '/Present Employee Data - Sheet4.csv'; // CHANGED: Updated filename
    
    public function __construct() {
        $this->loadCSV();
    }
    
    private function loadCSV() {
        if (!file_exists($this->csv_file)) {
            error_log("CSV file not found: " . $this->csv_file);
            return;
        }
        
        $file = fopen($this->csv_file, 'r');
        
        // Read header row
        $headers = fgetcsv($file);
        
        // Clean headers
        $headers = array_map('trim', $headers);
        
        // Map headers to expected columns
        $header_map = [
            'BID' => 'bid',
            'Name' => 'name',
            'Team' => 'team',
            'Departments' => 'department',
            'Designations' => 'designation',
            'Branch' => 'branch'
        ];
        
        while (($row = fgetcsv($file)) !== FALSE) {
            // Skip empty rows
            if (empty(array_filter($row))) continue;
            
            $row = array_map('trim', $row);
            
            // Check if this row has BID (employee data)
            if (!empty($row[0])) {
                $b_id = $row[0]; // BID
                $name = $row[1] ?? ''; // Name
                $team = $row[2] ?? ''; // Team
                $department = $row[3] ?? ''; // Departments
                $designation = $row[4] ?? ''; // Designations
                $branch = $row[5] ?? ''; // Branch
                
                // Clean up department names
                $department = trim($department);
                
                $this->employees[$b_id] = [
                    'id' => $b_id,
                    'name' => $name,
                    'designation' => $designation,
                    'department' => $department,
                    'branch' => $branch,
                    'team' => $team
                ];
            }
        }
        
        fclose($file);
    }
    
    public function getEmployee($b_id) {
        return $this->employees[$b_id] ?? null;
    }
    
    public function getAllEmployees() {
        return $this->employees;
    }
    
    public function getDepartments() {
        $depts = [];
        foreach ($this->employees as $emp) {
            if (!empty($emp['department'])) {
                $depts[$emp['department']] = true;
            }
        }
        return array_keys($depts);
    }
    
    public function getBranches() {
        $branches = [];
        foreach ($this->employees as $emp) {
            if (!empty($emp['branch'])) {
                $branches[$emp['branch']] = true;
            }
        }
        return array_keys($branches);
    }
    
    public function getDesignations() {
        $designations = [];
        foreach ($this->employees as $emp) {
            if (!empty($emp['designation'])) {
                $designations[$emp['designation']] = true;
            }
        }
        return array_keys($designations);
    }
    
    public function getTeams() {
        $teams = [];
        foreach ($this->employees as $emp) {
            if (!empty($emp['team'])) {
                $teams[$emp['team']] = true;
            }
        }
        return array_keys($teams);
    }
    
    public function searchEmployees($term) {
        $results = [];
        $term = strtolower($term);
        
        foreach ($this->employees as $emp) {
            if (strpos(strtolower($emp['id']), $term) !== false ||
                strpos(strtolower($emp['name']), $term) !== false ||
                strpos(strtolower($emp['department']), $term) !== false ||
                strpos(strtolower($emp['designation']), $term) !== false ||
                strpos(strtolower($emp['branch']), $term) !== false ||
                strpos(strtolower($emp['team']), $term) !== false) {
                $results[] = $emp;
            }
        }
        
        return $results;
    }
    
    public function filterByDepartment($department) {
        return array_filter($this->employees, function($emp) use ($department) {
            return strcasecmp($emp['department'], $department) == 0;
        });
    }
    
    public function filterByBranch($branch) {
        return array_filter($this->employees, function($emp) use ($branch) {
            return strcasecmp($emp['branch'], $branch) == 0;
        });
    }
    
    public function filterByDesignation($designation) {
        return array_filter($this->employees, function($emp) use ($designation) {
            return strcasecmp($emp['designation'], $designation) == 0;
        });
    }
    
    public function filterByTeam($team) {
        return array_filter($this->employees, function($emp) use ($team) {
            return strcasecmp($emp['team'], $team) == 0;
        });
    }
    
    public function getDepartmentStats() {
        $stats = [];
        foreach ($this->employees as $emp) {
            $dept = $emp['department'] ?: 'General';
            if (!isset($stats[$dept])) {
                $stats[$dept] = 0;
            }
            $stats[$dept]++;
        }
        arsort($stats);
        return $stats;
    }
    
    public function getTeamStats() {
        $stats = [];
        foreach ($this->employees as $emp) {
            $team = $emp['team'] ?: 'No Team';
            if (!isset($stats[$team])) {
                $stats[$team] = 0;
            }
            $stats[$team]++;
        }
        arsort($stats);
        return $stats;
    }
    
    public function getDesignationStats() {
        $stats = [];
        foreach ($this->employees as $emp) {
            $desig = $emp['designation'] ?: 'Employee';
            if (!isset($stats[$desig])) {
                $stats[$desig] = 0;
            }
            $stats[$desig]++;
        }
        arsort($stats);
        return $stats;
    }
    
    public function getBranchStats() {
        $stats = [];
        foreach ($this->employees as $emp) {
            $branch = $emp['branch'] ?: 'Main';
            if (!isset($stats[$branch])) {
                $stats[$branch] = 0;
            }
            $stats[$branch]++;
        }
        arsort($stats);
        return $stats;
    }
}
?>