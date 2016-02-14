<!DOCTYPE html>
<html>
<head>
    <script src='bower_components/jquery/dist/jquery.min.js'></script>
    <script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js'></script>
    <script src='bower_components/moment/min/moment.min.js'></script>
    <script src='bower_components/fullcalendar/dist/fullcalendar.js'></script>
    <link rel='stylesheet' href='bower_components/fullcalendar/dist/fullcalendar.css' />
    <link rel='stylesheet' href='bower_components/fullcalendar/dist/fullcalendar.print.css' media="print"/>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="http://code.jquery.com/ui/1.9.1/themes/cupertino/jquery-ui.css">
    <title>Booking system</title>
    <style type="text/css">
        body {
            margin: 40px 10px;
            padding: 0;
            font-family: "Lucida Grande",Helvetica,Arial,Verdana,sans-serif;
            font-size: 14px;
        }

        #calendar {
            max-width: 900px;
            margin: 0 auto;
        }
    </style>
</head>
<body>          
    <div id="calendar"></div>

    <div id="error_modal" class="modal fade" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body">
                    <p id="error_content"></p>
                </div>
            </div>
        </div>
    </div>


    <div id="customer_modal" class="modal fade" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Add reservation</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="customer">Customer's Name:</label>
                        <input type="text" class="form-control" id="customer" name="customer" required>
                    </div>
                    <div class="form-group">
                        <label for="customer_details">Details:</label>
                        <textarea class="form-control" rows="5" id="customer_details" name="customer_details"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="submit_customer" data-dismiss="modal">Ok</button>
                </div>
            </div>
        </div>
    </div>

    <div id="event_modal" class="modal fade" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">

                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Edit reservation</h4>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_customer_input">Customer's Name:</label>
                        <input type="text" class="form-control" id="edit_customer_input" name="edit_customer_input" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_customer_details_input">Details:</label>
                        <textarea class="form-control" rows="5" id="edit_customer_details_input" name="edit_customer_details_input"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="edit_customer" data-dismiss="modal">Edit</button>
                    <button type="button" class="btn btn-danger" id="delete_customer" data-dismiss="modal">Delete</button>\
                </div>
            </div>
        </div>
    </div>


    <div id="delete_modal" class="modal fade" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Are you sure you want to delete <span id="delete_customer_name"></span>?</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="delete_customer_submit" data-dismiss="modal">Yes</button>
                    <button type="button" class="btn btn-primary" data-dismiss="modal">No</button>
                </div>
            </div>
        </div>
    </div>


    <script type="text/javascript">
    $(document).ready(function() {
        var customer_date = "",
            customer_id = "",
            customer_details = "",
            reservation_limit = 2;

        $('#calendar').fullCalendar({
            editable: false, 
            displayEventTime: false,
            theme:true,         
            header: {
                right: 'prev,next today'
            },
            events: function(start, end, timezone, callback) {
            var date = $("#calendar").fullCalendar('getDate'),
                date_picked = new Date(date),
                year_start = '',
                month_start = '',
                number_of_days_start = '',
                year_end = ''.
                month_end = '',
                number_of_days_end = '';
                // If december
                if(date_picked.getMonth() == 11)
                {
                    year_start = date_picked.getFullYear();
                    month_start = date_picked.getMonth();  
                    number_of_days_start = new Date(year_start, month_start , 1).getDate();

                    year_end = date_picked.getFullYear() + 1;
                    month_end = 1;  
                    number_of_days_end = new Date(year_end, month_end , 0).getDate();

                    // console.log("end");
                    // console.log(year_end);
                    // console.log(month_end);
                    // console.log(number_of_days_end);
                } 
                else if(date_picked.getMonth() == 0)
                {
                    year_start = date_picked.getFullYear() - 1;
                    month_start = 12;  
                    number_of_days_start = new Date(year_start, month_start , 1).getDate();

                    // console.log("start");
                    // console.log(year_start);
                    // console.log(month_start);
                    // console.log(number_of_days_start);


                    year_end = date_picked.getFullYear();
                    month_end = 2;  
                    number_of_days_end = new Date(year_end, month_end , 0).getDate();

                    // console.log("end");
                    // console.log(year_end);
                    // console.log(month_end);
                    // console.log(number_of_days_end);
                } 
                else
                {
                    year_start = date_picked.getFullYear();
                    month_start = date_picked.getMonth();  
                    number_of_days_start = new Date(year_start, month_start , 1).getDate();

                    // console.log("start");
                    // console.log(year_start);
                    // console.log(month_start);
                    // console.log(number_of_days_start);


                    year_end = date_picked.getFullYear();
                    month_end = date_picked.getMonth() + 2;  
                    number_of_days_end = new Date(year_end, month_end , 0).getDate();

                    // console.log("end");
                    // console.log(year_end);
                    // console.log(month_end);
                    // console.log(number_of_days_end);
                
                }

                $.ajax({
                    url: 'process.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        type: 'fetch',
                        year_start: year_start,
                        month_start: month_start,
                        number_of_days_start: number_of_days_start,

                        year_end: year_end,
                        month_end: month_end,
                        number_of_days_end: number_of_days_end,
                    },
                    success: function(data){
                        console.log(data);
                        events_json = [];
                        for(d in data){
                            var reservation = data[d];
                            events_json.push({
                                id: reservation.id,
                                title: reservation.name,
                                start: reservation.start,
                                details: reservation.details,
                            });
                        }
                        callback(events_json);
                    },
                    error: function() {
                        alert('There was an error while fetching data.');
                    }
                });
            },
            dayClick: function(date){
                // console.log(date);
                $.ajax({
                    url: 'process.php',
                    type: 'POST',
                    data: {
                        type: 'check_limit',
                        date: date.format()
                    },
                    success: function(result){
                        if(result < reservation_limit){
                            $('#customer_modal').modal('show'); 
                            customer_date = date.format();
                        }else{
                            $('#error_content').html("Date picked has already reached its reservation limits");
                            $('#error_modal').modal('show'); 
                        }
                    }
                });
            },

            eventClick: function(event, jsEvent, view) {
                $('#event_modal').modal('show'); 
                $('#edit_customer_input').val(event.title);
                $('#edit_customer_details_input').val(event.details);
                customer_id = event.id;
                customer_details = event.details;
            },
 
        })

        $("#submit_customer").click(function(){
            if($("#customer").val() != ''){
                $.ajax({
                    url: 'process.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        type: 'new',
                        date: customer_date,
                        name: $("#customer").val(),
                        details: $("#customer_details").val()
                    },
                    success: function(data){
                        $('#calendar').fullCalendar('refetchEvents');
                    },
                    error: function() {
                        alert('There was an error while posting data.');
                    }
                });
            }

            $("#customer, #customer_details").val('');
        });

        $("#edit_customer").click(function(){
            if($("#edit_customer_input").val() != ''){
                $.ajax({
                    url: 'process.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        type: 'edit',
                        id: customer_id,
                        new_val: $("#edit_customer_input").val(),
                        details: $("#edit_customer_details_input").val()
                    },
                    success: function(data){
                        $('#calendar').fullCalendar('refetchEvents');
                    },
                    error: function() {
                        alert('There was an error while posting data.');
                    }
                });
            }
        });

        $("#delete_customer").click(function(){
            $('#delete_customer_name').html($("#edit_customer_input").val()); 
            $('#delete_modal').modal('show'); 
        });

        $("#delete_customer_submit").click(function(){
            $.ajax({
                url: 'process.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    type: 'remove',
                    id: customer_id
                },
                success: function(data){
                    $('#calendar').fullCalendar('refetchEvents');
                },
                error: function() {
                    alert('There was an error while deleting data.');
                }
            });
        });

    });

</script>
</body>
</html>