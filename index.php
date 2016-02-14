<!DOCTYPE html>
<html>
<head>
    <script src='bower_components/jquery/dist/jquery.min.js'></script>
    <script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js'></script>
    <script src='bower_components/moment/min/moment.min.js'></script>
    <script src='bower_components/fullcalendar/dist/fullcalendar.min.js'></script>
    <link rel='stylesheet' href='bower_components/fullcalendar/dist/fullcalendar.min.css' />
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

    <!-- This section will be for modals. These are hidden from the start. -->
    <!-- Error modal -->
    <div id="error_modal" class="modal fade" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body">
                    <p id="error_content"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Reservation Modal -->
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

    <!-- Edit Reservation modal -->
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

    <!-- Delete Reservation confirmation modal -->
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


            /* initialize the external events
            -----------------------------------------------------------------*/

            events: function(start, end, timezone, callback) {
                var date = $("#calendar").fullCalendar('getDate'),
                    date_picked = new Date(date),
                    year_start = '',
                    month_start = '',
                    number_of_days_start = '',
                    year_end = ''.
                    month_end = '',
                    number_of_days_end = '';
                    
                    /* Manipulation for date picked. Since months that will be fetched will be 1 month before and 1 month after 
                       for the month picked so the system won't be over poulated.
                       Since January and December will alter the year, we need to manipulate the data that will be passed to the backend. 
                    */

                    // This is for December
                    if(date_picked.getMonth() == 11){
                        year_start = date_picked.getFullYear();
                        month_start = date_picked.getMonth();  
                        number_of_days_start = new Date(year_start, month_start , 1).getDate();
                        year_end = date_picked.getFullYear() + 1;
                        month_end = 1;  
                        number_of_days_end = new Date(year_end, month_end , 0).getDate();
                    } 
                    // This is for January
                    else if(date_picked.getMonth() == 0) {
                        year_start = date_picked.getFullYear() - 1;
                        month_start = 12;  
                        number_of_days_start = new Date(year_start, month_start , 1).getDate();
                        year_end = date_picked.getFullYear();
                        month_end = 2;  
                        number_of_days_end = new Date(year_end, month_end , 0).getDate();
                    } 
                    // This is for every other month other than January and December
                    else {
                        year_start = date_picked.getFullYear();
                        month_start = date_picked.getMonth();  
                        number_of_days_start = new Date(year_start, month_start , 1).getDate();
                        year_end = date_picked.getFullYear();
                        month_end = date_picked.getMonth() + 2;  
                        number_of_days_end = new Date(year_end, month_end , 0).getDate();
                    }

                    // Fetching of data ajax that will be appended to the calendar. 
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
            /* This is the action when a space in a calendar dar is clicked and NOT the event.  
               First it will check if the date clicked reached its maximun reservation limit through the backend in ajax.
            */
            dayClick: function(date){
                $.ajax({
                    url: 'process.php',
                    type: 'POST',
                    data: {
                        type: 'check_limit',
                        date: date.format()
                    },
                    success: function(result){
                        // If the numbers reserved reached it limit, there will be a modal error. Else the user can add a reservation.
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
            // This action will be done when a customer is clicked in the calendar. It will show up an edit button.
            eventClick: function(event, jsEvent, view) {
                $('#event_modal').modal('show'); 
                $('#edit_customer_input').val(event.title);
                $('#edit_customer_details_input').val(event.details);
                customer_id = event.id;
                customer_details = event.details;
            },
 
        })

        // Submitting added customer ajax
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


        // Submitting edited customer ajax
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

        // Modal show for deleting ajax for confirmation
        $("#delete_customer").click(function(){
            $('#delete_customer_name').html($("#edit_customer_input").val()); 
            $('#delete_modal').modal('show'); 
        });

        // Deleting ajax submit
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