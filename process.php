<?php
include('config.php');

$type = $_POST['type'];

if($type == 'new')
{
	$name = $_POST['name'];
	$date = $_POST['date'];
	$details = $_POST['details'];
	$insert = mysqli_query($con, "INSERT INTO calendar(`name`, `startdate`,`details`) VALUES('$name','$date','$details')");
	$lastid = mysqli_insert_id($con);
	echo json_encode(array('status'=>'success','eventid'=>$lastid));
}

if($type == 'check_limit')
{
	$date = $_POST['date'];
	$query = mysqli_query($con, "SELECT count(*) as total FROM calendar WHERE startdate = '$date'");
	$fetch = mysqli_fetch_array($query,MYSQLI_ASSOC);

	echo $fetch["total"];
}

if($type == 'edit')
{
	$id = $_POST['id'];
	$new_val = $_POST['new_val'];
	$details = $_POST['details'];
	$update = mysqli_query($con,"UPDATE calendar SET name='$new_val',details='$details' where id='$id'");
	if($update)
		echo json_encode(array('status'=>'success'));
	else
		echo json_encode(array('status'=>'failed'));
}


if($type == 'remove')
{
	$id = $_POST['id'];
	$delete = mysqli_query($con,"DELETE FROM calendar where id='$id'");
	if($delete)
		echo json_encode(array('status'=>'success'));
	else
		echo json_encode(array('status'=>'failed'));
}

if($type == 'fetch')
{

	$year_start = $_POST['year_start'];
	$month_start = $_POST['month_start'];
	$number_of_days_start = $_POST['number_of_days_start'];

	$year_end = $_POST['year_end'];
	$month_end = $_POST['month_end'];
	$number_of_days_end = $_POST['number_of_days_end'];

	$datetime_start = new DateTime("$month_start/$number_of_days_start/$year_start");
	$datetime_end = new DateTime("$month_end/$number_of_days_end/$year_end");

	$datetime_start_format = $datetime_start->format("Y-m-d");
	$datetime_end_format = $datetime_end->format("Y-m-d");

	$events = array();
	$query = mysqli_query($con, "SELECT * FROM calendar WHERE startdate >= '$datetime_start_format' AND startdate <= '$datetime_end_format'");
	while($fetch = mysqli_fetch_array($query,MYSQLI_ASSOC))
	{
		$e = array();
	    $e['id'] = $fetch['id'];
	    $e['name'] = $fetch['name'];
	    $e['start'] = $fetch['startdate'];
	    $e['details'] = $fetch['details'];
	    array_push($events, $e);
	}
	echo json_encode($events);
}


?>