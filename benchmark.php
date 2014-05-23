<?php
echo __FILE__."\n";


class BenchMarkCmd
{
	var $parameter = array();
	var $cmds = array();
	var $cmds_paras = array();
	
	function BenchMarkCmd()
	{}
	function add_parameter($name, $array)
	{
		$this->parameter[$name] = $array;
	}
	function make_cmds($cmd)
	{
		$total_cmd = 1;
		foreach($this->parameter as $name => $values)
		{
			$total_cmd *= count($values);
		}
		for($i=0;$i<$total_cmd;$i++)
			$this->cmds[] = $cmd;
		
		$para_idx = 1;
		foreach($this->parameter as $name => $values) //3
		{
			$cmds_idx = 0;
			$values_count = count($values);
			for($r_idx=0; $r_idx < $para_idx; $r_idx++) //1 2 3
			{
				foreach($values as $value) //2 2 1
				{		
					for($i=0;$i < floor($total_cmd / $values_count / $para_idx) ;$i++)
					{
						
						$this->cmds[$cmds_idx] = str_replace("\$$name", $value, $this->cmds[$cmds_idx]);
						$this->cmds_paras[$cmds_idx][$name] = $value;
						$cmds_idx++;
					}
				}
			}
			$para_idx *= $values_count;
		}
		foreach($this->cmds_paras as $idx => &$value)
		{
			$value["cmd_idx"] = $idx;
			$value["cmd_md5"] = md5($this->cmds[$idx]);
		}
		sort($this->cmds_paras);
	}
};

class BenchMarkRun
{
	//variable
	var $project_name = "";
	var $project_record = "";
	var $c_cmds = NULL; //current cmds obj
	var $records = array();
	
	//config
	var $log_path = "logs";
	var $run_path = "runs";
	
	//sge run
	var $base_cpu = 6;
	var $sge_path = "tmp_sge";
	var $default_sge_cmd = "#!/bin/sh\n#$ -V\n#$ -S /bin/bash\n";
	
	//sh run
	var $sh_path = "tmp_sh";
	
	function get_basic_running_script(&$cmd_para)
	{
		$tmp_dir = dirname(__FILE__)."/{$this->run_path}/" . $cmd_para["cmd_md5"];	
		@mkdir($tmp_dir);
		
		$script = "echo '#project\t{$this->project_name}' \n";
		$script .= "echo '#parameter\t".json_encode($cmd_para)."' \n";
		$script .= "echo '#start\t'`date +%s.%3N`\n";
		$script .= "cd $tmp_dir\n";
		$script .= $this->c_cmds->cmds[$cmd_para["cmd_idx"]]."\n";
		$script .= "rm -r $tmp_dir\n";
		$script .= "echo '#end\t'`date +%s.%3N`\n";
		
		return $script;
	}
	function get_log_path(&$cmd_para)
	{
		$path = dirname(__FILE__)."/{$this->log_path}/log_". $cmd_para["cmd_md5"] .".log";
		return $path;
	}
	
	function get_sge_script_header(&$cmd_para)
	{
		$sge_cmd = $this->default_sge_cmd;
		if(isset($cmd_para["cpu"]))
			$sge_cmd .= "#$ -pe single " . ($cmd_para["cpu"]+$this->base_cpu) . "\n";
		else if(isset($cmd_para["cpus"]))
			$sge_cmd .= "#$ -pe single " . ($cmd_para["cpus"]+$this->base_cpu) . "\n";
		else
			$sge_cmd .= "#$ -pe single {$this->base_cpu}\n";
		$sge_cmd .= "#$ -o ".$this->get_log_path($cmd_para)."\n";
		$sge_cmd .= "#$ -j y\n";
		$sge_cmd .= "#Parameter\t" . json_encode($cmd_para) . "\n";
		$sge_cmd .= "#cmd\t" . $this->c_cmds->cmds[$cmd_para["cmd_idx"]]."\n";
		return $sge_cmd;
	}
	
	function default_run()
	{
		@mkdir($this->sh_path);
		foreach($this->c_cmds->cmds_paras as $cmd_para)
		{
			$this->record_run($cmd_para, "sh");
			$sh_cmd = $this->get_basic_running_script($cmd_para);
			$log_path = $this->get_log_path($cmd_para);
			
			file_put_contents($this->sh_path."/sh_".$cmd_para["cmd_md5"].".sh" , $sh_cmd);
			echo $this->c_cmds->cmds[$cmd_para["cmd_idx"]]."\n";
			//echo shell_exec("sh ".$this->sh_path."/sh_".$cmd_para["cmd_md5"].".sh >$log_path 2>&1");
			echo "\n";
		}
	}
	function sge_run()
	{
		@mkdir($this->sge_path);
		
		foreach($this->c_cmds->cmds_paras as $cmd_para)
		{
			$this->record_run($cmd_para, "sge");
			$sge_cmd = $this->get_sge_script_header($cmd_para);
			$sge_cmd .= $this->get_basic_running_script($cmd_para);
			
			file_put_contents($this->sge_path."/sge_".$cmd_para["cmd_md5"].".sge" , $sge_cmd);
			echo $this->c_cmds->cmds[$cmd_para["cmd_idx"]]."\n";
			echo shell_exec("qsub ".$this->sge_path."/sge_".$cmd_para["cmd_md5"].".sge");
			echo "\n";
		}
	}
	
	// Record
	function check_record()
	{
		if( !file_exists($this->project_record) )
		{
			file_put_contents($this->project_record, "#project {$this->project_name}\n");
		}
	}
	function record_run(&$cmd_para, $type="default")
	{
		$record = "#Parameter\t$type\t" . json_encode($cmd_para) . "\n";
		file_put_contents($this->project_record, $record, FILE_APPEND);
	}
	
	
	//get log result and calculate time
	function print_average_time($merge_key = array())
	{
		$this->read_record();
		$this->read_result_time();
		$average_time = $this->calculate_average_time($merge_key);
		
		$output = "key_name\tnum\tsum\tavg\tsd\tdetail\n";
		foreach($average_time as $key => $counts)
		{
			$output .= $key . "\t";
			$output .= $counts["num"] . "\t";
			$output .= number_format($counts["sum"], 3, '.', '') . "\t";
			$output .= number_format($counts["avg"], 3, '.', '') . "\t";
			$output .= number_format($counts["sd"], 3, '.', '') . "\t";
			foreach($counts["each"] as $time)
			{
				$output .= number_format($time, 3, '.', '') . ", ";
			}
			$output .= "\n";
		}
		echo $output;
	}
	
	function read_record()
	{
		//$this->records;
		$lines = File($this->project_record);
		foreach($lines as $line)
		{
			if($line[0] != "#")
				continue;
			if(substr($line, 0, 10) == "#Parameter")
			{
				$tabs = explode("\t", $line);
				$this->records[ $tabs[1] ][] = json_decode($tabs[2], true);
			}
		}
		
	}
	function read_result_time()
	{
		foreach($this->records as $type => &$paras)
		{
			foreach($paras as &$para)
			{
				$file = $this->log_path . "/log_" . $para["cmd_md5"] . ".log";
				$para["run_time"] = $this->get_time($file);
			}
		}
		//print_r($this->records);
	}
	function get_time($file)
	{
		if(!file_exists($file))
		{
			echo "Error. File not exists $file\n";
			return 0;
		}
		$start = 0.0;
		$end = 0.0;
		
		$fp = fopen($file, "r");
		while(!feof($fp))
		{
			$line = trim(fgets($fp));
			if($line == "")
				continue;
			$tabs = explode("\t", $line);
			if($tabs[0] == "#start")
				$start = $tabs[1];
			if($tabs[0] == "#end")
				$end = $tabs[1];
		}
		fclose($fp);
		if($end < $start)
		{
			echo "Error. time record error, or jobs not finish [$file]\n";
			return 0;
		}
		//echo ($end - $start) . "\n";
		return $end - $start;
	}
	function calculate_average_time(&$merge_key)
	{
		//"cmd_idx" "cmd_md5" "run_time"
		$average_time = array();
		foreach($this->records as $type => &$paras)
		{
			foreach($paras as &$para)
			{
				$time_key = "";
				foreach($merge_key as $mkey)
				{
					$time_key .= "(" . $mkey .":". $para[$mkey].")";
				}
				if(!isset($average_time[$time_key]))
					$average_time[$time_key] = array("num"=>0, "sum"=>0, "avg"=>0, "sd"=>0, "each"=>array());
				$average_time[$time_key]["num"] ++;
				$average_time[$time_key]["sum"] += $para["run_time"];
				$average_time[$time_key]["each"][] = $para["run_time"];
			}
		}
		// average
		foreach($average_time as $key => &$counts)
		{
			$counts["avg"] = $counts["sum"] / $counts["num"];
		}
		
		//sd
		foreach($average_time as $key => &$counts)
		{
			$t = 0;
			foreach($counts["each"] as $each)
			{
				$t += pow($each - $counts["avg"], 2);
			}
			$counts["sd"] = sqrt( $t / ($counts["num"]-1) );
		}
		
		return $average_time;
	}
};


class BenchMark extends BenchMarkRun
{
	//config
	
	//variable
	var $BM_cmds = array();
	
	//extends variables
	//	var $c_cmds;
	
	function BenchMark($prj_name)
	{
		$this->select_project($prj_name);
		@mkdir(dirname(__FILE__)."/{$this->log_path}");
		@mkdir(dirname(__FILE__)."/{$this->run_path}");
	}
	function select_project($prj_name)
	{
		$this->project_name = $prj_name;
		$this->project_record = urlencode($prj_name).".bm";
		$this->check_record();
		if(!isset($this->BM_cmds[$prj_name]))
		{
			$this->BM_cmds[$prj_name] = new BenchMarkCmd();
		}
		$this->c_cmds = &$this->BM_cmds[$this->project_name];
	}
	function add_parameter($name, $array)
	{
		$this->c_cmds->parameter[$name] = $array;
	}
	function run($cmd, $type="")
	{
		$this->c_cmds->make_cmds($cmd);
		switch($type)
		{
			case "sge":
				$this->sge_run();
				break;
			default:
				$this->default_run();
				break;
		}
	}
	
};


// example usage

$p_sbwt = "/home/andy/publish/sBWT/bin/sbwt_linux";
$p_genome = "/home/andy/andy/pokemon_0505/sbwt_test3/genome_hg19";
$p_reads = "/home/andy/andy/pokemon_0505/sbwt_test3/reads_hg19";
$p_index = "/home/andy/andy/pokemon_0505/sbwt_test3/sbwt/index";
$p_log = "/home/andy/andy/pokemon_0505/sbwt_test3/sbwt/log";
$p_result = "/home/andy/andy/pokemon_0505/sbwt_test3/sbwt/result";

/*
$bb = new BenchMark("sbwt_speed_test_build");
$bb->base_cpu = 8;
$bb->add_parameter("repeat", array(1,2,3,4,5) );
$bb->add_parameter("genome", array(1,2) );
$bb->add_parameter("interval", array(64) );
$cmd = "time $p_sbwt build -p $p_index/hg19_\$genomeX_test_400M_\$interval_\$repeat -i $p_genome/hg19_\$genomeX_test_400M.fa -s \$interval -f > ";
$cmd .= "$p_log/log_build_r_\$repeat_g_\$genome_i_\$interval.log 2>&1";
$bb->run($cmd, "sge");
exit();
*/
$bb = new BenchMark("sbwt_speed_test_build");
$bb->print_average_time(array("genome", "interval"));
exit();

exit();
$bm = new BenchMark("sbwt_speed_test_search");
$bm->base_cpu = 6;
$bm->add_parameter("repeat", array(1,2,3,4,5) );
$bm->add_parameter("genome", array(1,2,4,8,16) );
$bm->add_parameter("len", array(40,60,80,100) );
$bm->add_parameter("cpu", array(1) );
$bm->add_parameter("interval", array(64) );

$cmd = "time $p_sbwt map -p $p_index/hg19_\$genomeX_test_400M_\$interval_\$repeat -i $p_reads/s_hg19_400M_reads_\$len.fq -n \$cpu -o ";
$cmd .= "$p_result/result_r_\$repeat_g_\$genome_l_\$len_c_\$cpu_\$interval.sam > ";
$cmd .= "$p_log/log_search_r_\$repeat_g_\$genome_l_\$len_c_\$cpu_\$interval.log 2>&1";
$bm->sge_run($cmd);


?>