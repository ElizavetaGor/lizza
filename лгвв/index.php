<?php
/* cspell:disable */
// Secure connection to database 'uzers'
// Credentials are taken from environment variables for security.
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'admin';
$password = getenv('DB_PASS') ?: 'admin';
$database = getenv('DB_NAME') ?: 'uzers';
/* cspell:enable */

// Connect to MySQL
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create table 'users' if not exists
$sql_create = "CREATE TABLE IF NOT EXISTS users (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql_create);

// CRUD handling
$message = '';
$messageType = 'success';

// CREATE
if (isset($_POST['create'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    
    if (!empty($name) && !empty($email)) {
        $stmt = $conn->prepare("INSERT INTO users (name, email, phone) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $email, $phone);
        if ($stmt->execute()) {
            $message = "User added successfully!";
        } else {
            $message = "Error: " . $stmt->error;
            $messageType = 'error';
        }
        $stmt->close();
    } else {
        $message = "Name and email are required!";
        $messageType = 'error';
    }
}

// UPDATE
if (isset($_POST['update'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    
    if ($id > 0 && !empty($name) && !empty($email)) {
        $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?");
        /* cspell:disable-next-line */
        $stmt->bind_param("sssi", $name, $email, $phone, $id);
        if ($stmt->execute()) {
            $message = "User updated!";
        } else {
            $message = "Update error: " . $stmt->error;
            $messageType = 'error';
        }
        $stmt->close();
    } else {
        $message = "Invalid data for update!";
        $messageType = 'error';
    }
}

// DELETE
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = "User deleted!";
        } else {
            $message = "Deletion error: " . $stmt->error;
            $messageType = 'error';
        }
        $stmt->close();
    }
    // Redirect to avoid re-delete on page refresh
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// READ: fetch all users
$result = $conn->query("SELECT * FROM users ORDER BY id DESC");
$users = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Get user data for editing (if chosen)
$editUser = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $editId);
    $stmt->execute();
    $editUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CRUD interface for database <?= htmlspecialchars($database) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #8ffdeeff;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: auto;
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 { margin-top: 0; color: #333; }
        .message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .form-card {
            background: #f9f9f9;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
            border: 1px solid #ddd;
        }
        .form-card h3 { margin-top: 0; }
        .form-group { margin-bottom: 12px; }
        label {
            display: inline-block;
            width: 100px;
            font-weight: bold;
        }
        input[type="text"], input[type="email"] {
            padding: 6px 10px;
            width: 250px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 5px;
        }
        button:hover { background: #0056b3; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th { background-color: #c0dbe7ff; }
        .actions a {
            margin-right: 8px;
            text-decoration: none;
            padding: 4px 8px;
            border-radius: 3px;
            display: inline-block;
        }
        .edit-link { background: #28a745; color: white; }
        .delete-link { background: #dc3545; color: white; }
        .edit-link:hover { background: #218838; }
        .delete-link:hover { background: #c82333; }
        .cancel-link {
            background: #c1ddf5ff;
            color: white;
            padding: 5px 10px;
            text-decoration: none;
            border-radius: 4px;
            margin-left: 10px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>📋 Manage Users (database: <?= htmlspecialchars($database) ?>)</h1>

    <?php if ($message): ?>
        <div class="message <?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- CREATE form -->
    <div class="form-card">
        <h3>➕ Add new user</h3>
        <form method="post">
            <div class="form-group">
                <label for="create_name">Name:</label>
                <input type="text" id="create_name" name="name" required>
            </div>
            <div class="form-group">
                <label for="create_email">Email:</label>
                <input type="email" id="create_email" name="email" required>
            </div>
            <div class="form-group">
                <label for="create_phone">Phone:</label>
                <input type="text" id="create_phone" name="phone">
            </div>
            <button type="submit" name="create">Save</button>
        </form>
    </div>

    <!-- UPDATE form (shown only when editing) -->
    <?php if ($editUser): ?>
    <div class="form-card" style="background:#fff3cd; border-color:#ffc107;">
        <h3>✏️ Edit user #<?= $editUser['id'] ?></h3>
        <form method="post">
            <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
            <div class="form-group">
                <label for="edit_name">Name:</label>
                <input type="text" id="edit_name" name="name" value="<?= htmlspecialchars($editUser['name']) ?>" required>
            </div>
            <div class="form-group">
                <label for="edit_email">Email:</label>
                <input type="email" id="edit_email" name="email" value="<?= htmlspecialchars($editUser['email']) ?>" required>
            </div>
            <div class="form-group">
                <label for="edit_phone">Phone:</label>
                <input type="text" id="edit_phone" name="phone" value="<?= htmlspecialchars($editUser['phone']) ?>">
            </div>
            <button type="submit" name="update">Update</button>
            <a href="?cancel" class="cancel-link">Cancel</a>
        </form>
    </div>
    <?php endif; ?>

    <!-- READ table -->
    <h3>📋 User list</h3>
    <?php if (!empty($users)): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Created at</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr>
                <td><?= $user['id'] ?></td>
                <td><?= htmlspecialchars($user['name']) ?></td>
                <td><?= htmlspecialchars($user['email']) ?></td>
                <td><?= htmlspecialchars($user['phone']) ?></td>
                <td><?= $user['created_at'] ?></td>
                <td class="actions">
                    <a href="?edit=<?= $user['id'] ?>" class="edit-link">Edit</a>
                    <a href="?delete=<?= $user['id'] ?>" class="delete-link" onclick="return confirm('Delete this user?')">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p>No users yet. Add the first one!</p>
    <?php endif; ?>
</div>
</body>
</html>
<?php
$conn->close();
?>