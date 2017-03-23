<?php

function responseSuccess($message) {
  return response()->json([
    'success' => true,
    'message' => $message,
  ], 200);
}

function responseFailure($message) {
  return response()->json([
    'success' => false,
    'message' => $message,
  ], 400);
}

function responseData($data, $message = '') {
  $response = [
    'success' => true,
    'data' => $data,
  ];
  if (!empty($message)) {
    $response['message'] = $message;
  }

  return response()->json($response, 200);
}

?>
